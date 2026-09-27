<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use RuntimeException;

/**
 * Private one-time token used only while Core Update maintenance mode is active.
 * It lets the updater boot the newly switched release for a real HTTP smoke probe
 * while every normal visitor still receives the dependency-free maintenance page.
 */
final class CoreUpdateProbeTokenStore
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function create(): string
    {
        $token = bin2hex(random_bytes(32));
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.53f589827e93'));
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $token . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.761903b2fb0c'));
        }
        @chmod($path, 0600);

        return $token;
    }

    public function verify(string $candidate): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '' || strlen($candidate) > 256) {
            return false;
        }
        $raw = @file_get_contents($this->path());
        $stored = is_string($raw) ? trim($raw) : '';

        return $stored !== '' && hash_equals($stored, $candidate);
    }

    public function clear(): void
    {
        @unlink($this->path());
    }

    public function path(): string
    {
        return rtrim($this->projectDir, '/\\') . '/var/update/probe.token';
    }
}
