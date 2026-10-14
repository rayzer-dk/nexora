<?php

declare(strict_types=1);

namespace Example\OrderHook;

/**
 * Append-only journal of placed orders in a JSON-lines file.
 *
 * The platform gives a trusted module no database or storage service, so the module owns a plain directory.
 * It lives outside the versioned install folder, which means the data survives an update of the module.
 */
final class OrderLog
{
    public function __construct(private readonly string $dir)
    {
    }

    /** The install path is `<project>/var/extensions/installed/<code>/<version>`; data goes to `<project>/var/extension-data/<code>`. */
    public static function forInstallPath(string $installPath, string $code): self
    {
        $var = dirname($installPath, 4);
        if (basename($var) !== 'var') {
            $var = sys_get_temp_dir();
        }

        return new self($var . '/extension-data/' . $code);
    }

    /** @param array<string,mixed> $order */
    public function append(string $eventId, array $order): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('The data directory cannot be created.');
        }
        foreach ($this->tail(200) as $known) {
            if (($known['event_id'] ?? null) === $eventId) {
                return false; // the outbox may deliver the same event twice
            }
        }
        $line = json_encode(['event_id' => $eventId, 'logged_at' => gmdate('c')] + $order, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->file(), $line . "\n", FILE_APPEND | LOCK_EX);

        return true;
    }

    /** @return list<array<string,mixed>> newest last */
    public function tail(int $limit): array
    {
        $lines = is_file($this->file()) ? (file($this->file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $rows = [];
        foreach (array_slice($lines, -$limit) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** Orders logged since the previous call; remembers the position in `digest.json`. */
    public function digest(): string
    {
        $state = is_file($this->dir . '/digest.json') ? json_decode((string) file_get_contents($this->dir . '/digest.json'), true) : [];
        $from = is_array($state) ? (int) ($state['lines'] ?? 0) : 0;
        $lines = is_file($this->file()) ? (file($this->file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $totals = [];
        foreach (array_slice($lines, $from) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $currency = (string) ($row['currency'] ?? '?');
                $totals[$currency] = ($totals[$currency] ?? 0) + (int) ($row['total_minor'] ?? 0);
            }
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('The data directory cannot be created.');
        }
        file_put_contents($this->dir . '/digest.json', json_encode(['lines' => count($lines), 'at' => gmdate('c')], JSON_THROW_ON_ERROR), LOCK_EX);
        $count = count($lines) - $from;
        $sums = [];
        foreach ($totals as $currency => $minor) {
            $sums[] = sprintf('%s %.2f', $currency, $minor / 100);
        }

        return $count === 0 ? 'New orders: 0' : sprintf('New orders: %d (%s)', $count, implode(', ', $sums));
    }

    private function file(): string
    {
        return $this->dir . '/orders.jsonl';
    }
}
