<?php

declare(strict_types=1);

namespace Commerce\Core\Scheduler;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Core\I18n\CanonicalUiText;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Runs the due built-in scheduled tasks in one bounded pass. One implementation for every trigger:
 * the server cron (CLI), the web-cron URL, pseudo-cron (after a web response) and the "Run now" button.
 * A database advisory lock guarantees that two passes never overlap.
 */
final class CronRunner
{
    public const SOURCES = ['cli', 'web', 'pseudo', 'manual'];
    private const LOCK = 'nexora_commerce_cron_runner';

    public function __construct(
        private readonly Connection $db,
        private readonly ScheduledTaskRegistry $registry,
        private readonly SystemSettingStore $store,
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @param ?Application $application console application to resolve commands from (CLI passes its own; web builds one)
     * @param float $budgetSeconds stop starting new tasks after this many seconds (0 = unlimited)
     * @param ?callable(string,bool):void $progress called with a line and an "is error" flag
     * @return array{locked:bool,unknown:bool,ran:list<string>,failed:list<string>,skipped:int}
     */
    public function run(string $source, ?string $task = null, bool $force = false, float $budgetSeconds = 0.0, ?Application $application = null, ?callable $progress = null): array
    {
        $result = ['locked' => false, 'unknown' => false, 'ran' => [], 'failed' => [], 'skipped' => 0];
        $source = in_array($source, self::SOURCES, true) ? $source : 'manual';
        $tasks = $this->registry->all();
        if ($task !== null && $task !== '') {
            if (!isset($tasks[$task])) {
                $result['unknown'] = true;

                return $result;
            }
            $tasks = [$task => $tasks[$task]];
        }
        if ((int) $this->db->fetchOne('SELECT GET_LOCK(?,0)', [self::LOCK]) !== 1) {
            $result['locked'] = true;

            return $result;
        }
        $started = microtime(true);
        try {
            $application ??= $this->application();
            foreach ($tasks as $code => $definition) {
                if (!$force && !$this->due($code)) {
                    ++$result['skipped'];
                    continue;
                }
                if ($budgetSeconds > 0 && (microtime(true) - $started) > $budgetSeconds) {
                    ++$result['skipped'];
                    continue;
                }
                $ok = $this->runTask($application, $code, $definition, $progress);
                $result[$ok ? 'ran' : 'failed'][] = $code;
            }
            $this->recordTick($source, $result);
        } finally {
            try {
                $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    /** @return array{at:?string,age_seconds:?int,source:?string,ran:int,failed:int} last completed pass, from the tick record or the newest task state */
    public function lastTick(): array
    {
        $tick = $this->store->getArray('cron.last_run') ?? [];
        $at = is_string($tick['at'] ?? null) ? (string) $tick['at'] : null;
        $stateAt = null;
        try {
            $value = $this->db->fetchOne('SELECT MAX(last_finished_at) FROM mc_scheduled_task_state');
            $stateAt = is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
        }
        $best = null;
        foreach ([$at, $stateAt] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $time = strtotime(substr($candidate, 0, 19) . ' UTC');
            if ($time !== false && ($best === null || $time > $best)) {
                $best = $time;
            }
        }

        return [
            'at' => $best !== null ? gmdate('Y-m-d H:i:s', $best) : null,
            'age_seconds' => $best !== null ? max(0, time() - $best) : null,
            'source' => is_string($tick['source'] ?? null) ? (string) $tick['source'] : null,
            'ran' => (int) ($tick['ran'] ?? 0),
            'failed' => (int) ($tick['failed'] ?? 0),
        ];
    }

    private function application(): Application
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return $application;
    }

    /** @param array{ran:list<string>,failed:list<string>} $result */
    private function recordTick(string $source, array $result): void
    {
        $this->store->setArray('cron.last_run', ['at' => $this->now(), 'source' => $source, 'ran' => count($result['ran']), 'failed' => count($result['failed'])]);
    }

    /** @param array{label:string,command:string,args:array<string,string|bool|int>,interval:int,group:string,description:string,handler?:callable} $task */
    private function runTask(Application $application, string $code, array $task, ?callable $progress): bool
    {
        $started = microtime(true);
        $this->upsert($code, ['last_started_at' => $this->now(), 'last_status' => 'running', 'last_message' => null]);
        $buffer = new BufferedOutput();
        try {
            if (isset($task['handler'])) {
                // a task of a trusted extension: success unless the handler throws
                $returned = ($task['handler'])();
                $message = $this->clean(is_string($returned) ? $returned : '');
                $ok = true;
            } else {
                $command = $application->find($task['command']);
                $args = ['command' => $task['command']];
                foreach ($task['args'] as $k => $v) {
                    $args[$k] = $v;
                }
                $child = new ArrayInput($args);
                $child->setInteractive(false);
                $exit = $command->run($child, $buffer);
                $message = $this->clean($buffer->fetch());
                $ok = $exit === Command::SUCCESS;
            }
            $this->upsert($code, ['last_finished_at' => $this->now(), 'last_status' => $ok ? 'success' : 'failed', 'last_message' => $message, 'last_duration_ms' => (int) round((microtime(true) - $started) * 1000), 'next_due_at' => $this->next($task['interval'])], true, !$ok);
            if ($progress !== null) {
                $progress(sprintf('%s: %s%s', $code, $ok ? 'OK' : 'FAILED', $message !== '' ? ' · ' . $message : ''), !$ok);
            }

            return $ok;
        } catch (\Throwable $e) {
            $message = $this->clean($e->getMessage());
            $this->upsert($code, ['last_finished_at' => $this->now(), 'last_status' => 'failed', 'last_message' => $message, 'last_duration_ms' => (int) round((microtime(true) - $started) * 1000), 'next_due_at' => $this->next($task['interval'])], true, true);
            if ($progress !== null) {
                $progress($code . ': ' . $message, true);
            }

            return false;
        }
    }

    private function due(string $code): bool
    {
        try {
            $next = $this->db->fetchOne('SELECT next_due_at FROM mc_scheduled_task_state WHERE task_code=?', [$code]);

            return $next === false || $next === null || strtotime((string) $next . ' UTC') <= time();
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param array<string,mixed> $data */
    private function upsert(string $code, array $data, bool $incrementRun = false, bool $incrementFail = false): void
    {
        $exists = (bool) $this->db->fetchOne('SELECT 1 FROM mc_scheduled_task_state WHERE task_code=?', [$code]);
        if (!$exists) {
            $this->db->insert('mc_scheduled_task_state', array_merge(['task_code' => $code, 'run_count' => 0, 'fail_count' => 0], $data));
        } else {
            $this->db->update('mc_scheduled_task_state', $data, ['task_code' => $code]);
        }
        if ($incrementRun) {
            $this->db->executeStatement('UPDATE mc_scheduled_task_state SET run_count=run_count+1, fail_count=fail_count+? WHERE task_code=?', [$incrementFail ? 1 : 0, $code]);
        }
    }

    private function next(int $interval): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s.u');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function clean(string $message): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

        return mb_substr(strip_tags($message), 0, 900, 'UTF-8');
    }
}
