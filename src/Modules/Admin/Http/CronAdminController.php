<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Scheduler\CronCommandHint;
use Commerce\Core\Scheduler\CronRunner;
use Commerce\Core\Scheduler\CronSettings;
use Commerce\Core\Scheduler\ScheduledTaskRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CronAdminController extends AbstractController
{
    /** A healthy 5-minute cron never leaves a gap this long; beyond it the page warns. */
    private const STALE_SECONDS = 900;

    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly ScheduledTaskRegistry $registry,
        private readonly Connection $db,
        private readonly CronRunner $runner,
        private readonly CronSettings $settings,
        private readonly CronCommandHint $hint,
        #[Autowire('%commerce.app_public_url%')] private readonly string $publicUrl = '',
    ) {
    }

    #[Route('/admin/system/cron', name: 'admin_system_cron', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        $states = [];
        try {
            foreach ($this->db->fetchAllAssociative('SELECT * FROM mc_scheduled_task_state ORDER BY task_code') as $row) {
                $states[(string) $row['task_code']] = $row;
            }
        } catch (\Throwable) {
        }
        $tick = $this->runner->lastTick();
        $age = $tick['age_seconds'];
        $status = $age === null ? 'never' : ($age > self::STALE_SECONDS ? 'stale' : 'ok');
        $realCron = $status === 'ok' && in_array($tick['source'], ['cli', 'web'], true);
        $pseudoOn = $this->settings->pseudoEnabled();
        $mode = $realCron ? 'server' : ($pseudoOn ? 'pseudo' : 'none');
        $base = rtrim($this->publicUrl !== '' && str_starts_with($this->publicUrl, 'http') ? $this->publicUrl : $request->getSchemeAndHttpHost(), '/');
        $webUrl = $base . '/cron/' . $this->settings->token();
        $rows = [];
        foreach ($this->registry->all() as $code => $task) {
            $state = $states[$code] ?? null;
            $rows[] = [
                'code' => $code,
                'label' => $task['label'],
                'description' => $task['description'],
                'interval_minutes' => max(1, (int) round($task['interval'] / 60)),
                'last_at' => $state['last_finished_at'] ?? null,
                'last_ago' => $this->minutesSince($state['last_finished_at'] ?? null),
                'status' => $state['last_status'] ?? null,
                'next_in' => $this->minutesUntil($state['next_due_at'] ?? null),
                'message' => $state['last_message'] ?? null,
                'duration_ms' => $state['last_duration_ms'] ?? null,
            ];
        }

        return $this->render('@storefront/admin/system/cron.html.twig', [
            'rows' => $rows,
            'cron_status' => $status,
            'tick' => $tick,
            'stale_minutes' => $age !== null ? intdiv($age, 60) : null,
            'stale_limit_minutes' => intdiv(self::STALE_SECONDS, 60),
            'pseudo_enabled' => $pseudoOn,
            'mode' => $mode,
            'line' => $this->hint->line(),
            'command' => $this->hint->command(),
            'schedule' => $this->hint->schedule(),
            'php_binary' => $this->hint->phpBinary(),
            'console_path' => $this->hint->consolePath(),
            'web_url' => $webUrl,
            'web_line' => $this->hint->webLine($webUrl),
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ]);
    }

    #[Route('/admin/system/cron/run', name: 'admin_system_cron_run', methods: ['POST'])]
    public function run(Request $request): RedirectResponse
    {
        $this->guard($request);
        $code = trim((string) $request->request->get('task', ''));
        @ignore_user_abort(true);
        @set_time_limit(120);
        try {
            $lines = [];
            $result = $this->runner->run('manual', $code !== '' ? $code : null, $code !== '', 45.0, null, static function (string $line, bool $error) use (&$lines): void {
                $lines[] = ($error ? '! ' : '') . mb_substr($line, 0, 400);
            });
            if ($lines === [] && !$result['locked'] && !$result['unknown']) {
                $lines[] = CanonicalUiText::get('admin.system.cron.output_nothing_due');
            }
            foreach ($lines as $line) {
                $this->addFlash('cron_output', $line);
            }
        } catch (\Throwable) {
            $this->addFlash('error', CanonicalUiText::get('admin.system.cron.flash_run_failed'));

            return $this->redirectToRoute('admin_system_cron');
        }
        if ($result['unknown']) {
            $this->addFlash('error', CanonicalUiText::get('admin.system.cron.flash_unknown_task'));
        } elseif ($result['locked']) {
            $this->addFlash('warning', CanonicalUiText::get('admin.system.cron.flash_busy'));
        } else {
            $this->addFlash($result['failed'] === [] ? 'success' : 'warning', CanonicalUiText::get('admin.system.cron.flash_run_done', ['ran' => count($result['ran']), 'failed' => count($result['failed']), 'skipped' => $result['skipped']]));
        }

        return $this->redirectToRoute('admin_system_cron');
    }

    #[Route('/admin/system/cron/settings', name: 'admin_system_cron_settings', methods: ['POST'])]
    public function saveSettings(Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->settings->setPseudoEnabled($request->request->getBoolean('pseudo'));
        $this->addFlash('success', CanonicalUiText::get('admin.system.cron.flash_saved'));

        return $this->redirectToRoute('admin_system_cron');
    }

    #[Route('/admin/system/cron/token', name: 'admin_system_cron_token', methods: ['POST'])]
    public function regenerateToken(Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->settings->regenerateToken();
        $this->addFlash('success', CanonicalUiText::get('admin.system.cron.flash_token'));

        return $this->redirectToRoute('admin_system_cron');
    }

    private function guard(Request $request): void
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_cron', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }

    private function minutesSince(mixed $value): ?int
    {
        $time = is_string($value) && $value !== '' ? strtotime(substr($value, 0, 19) . ' UTC') : false;

        return $time === false ? null : max(0, intdiv(time() - $time, 60));
    }

    /** Negative when overdue. */
    private function minutesUntil(mixed $value): ?int
    {
        $time = is_string($value) && $value !== '' ? strtotime(substr($value, 0, 19) . ' UTC') : false;

        return $time === false ? null : intdiv($time - time(), 60);
    }
}
