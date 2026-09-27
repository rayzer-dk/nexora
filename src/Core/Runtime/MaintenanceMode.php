<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final readonly class MaintenanceMode
{
    public function __construct(private string $projectDir)
    {
    }

    public function enable(string $reason = 'maintenance', int $retryAfter = 30): void
    {
        $retryAfter = max(5, min(3600, $retryAfter));
        $payload = json_encode([
            'enabled_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'reason' => mb_substr(trim($reason), 0, 190, 'UTF-8'),
            'retry_after' => $retryAfter,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->atomicWrite($this->flagPath(), $payload . "\n", 0640);
    }

    public function disable(): void
    {
        $path = $this->flagPath();
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.882d87ed3458'));
        }
    }

    /** @return array{enabled_at:string,reason:string,retry_after:int}|null */
    public function state(): ?array
    {
        $raw = @file_get_contents($this->flagPath());
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['enabled_at' => '', 'reason' => 'maintenance', 'retry_after' => 30];
        }
        if (!is_array($data)) {
            return null;
        }
        return [
            'enabled_at' => (string) ($data['enabled_at'] ?? ''),
            'reason' => (string) ($data['reason'] ?? 'maintenance'),
            'retry_after' => max(5, min(3600, (int) ($data['retry_after'] ?? 30))),
        ];
    }

    public function isEnabled(): bool
    {
        return is_file($this->flagPath());
    }

    public function flagPath(): string
    {
        return rtrim($this->projectDir, '/\\') . '/var/maintenance.flag';
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0370ac3e08fe'));
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ccca388c721a'));
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5d30ca85c916'));
        }
    }
}
