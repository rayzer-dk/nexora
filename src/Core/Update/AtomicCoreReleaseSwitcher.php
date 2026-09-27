<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Commerce\Core\Recovery\ManagedPlatformPaths;
use RuntimeException;
use Throwable;

/**
 * Replaces only platform-owned paths. Runtime secrets, user media and third-party
 * extension storage are intentionally outside the switch set.
 */
final class AtomicCoreReleaseSwitcher
{
    /** @var list<string> */
    private array $swappedDirectories = [];

    /** @var array<string,string|null> */
    private array $swappedFiles = [];

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return array{quarantine:string,directories:list<string>,files:list<string>} */
    public function apply(string $releaseDir): array
    {
        $releaseDir = rtrim($releaseDir, '/\\');
        if (!is_dir($releaseDir)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b44e57344a5f'));
        }
        $includeVendor = is_dir($releaseDir . '/vendor');
        $this->assertDependencySafety($releaseDir, $includeVendor);
        $directories = ManagedPlatformPaths::updateDirectories($includeVendor);
        $files = ManagedPlatformPaths::updateFiles();
        $quarantine = $this->projectPath('var/update/quarantine/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)));
        $this->ensureDirectory($quarantine, 0750);
        $this->swappedDirectories = [];
        $this->swappedFiles = [];

        try {
            foreach ($directories as $relative) {
                $source = $releaseDir . '/' . $relative;
                if (!is_dir($source)) {
                    if (in_array($relative, ['vendor', 'public/build'], true)) {
                        continue;
                    }
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d755df3c2d71') . $relative . '.');
                }
                $target = $this->projectPath($relative);
                $backup = $quarantine . '/directories/' . $relative;
                $this->ensureDirectory(dirname($target), 0750);
                $this->ensureDirectory(dirname($backup), 0750);
                if (file_exists($target) || is_link($target)) {
                    if (!@rename($target, $backup)) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.315f0ee8f976') . $relative . '.');
                    }
                    $this->swappedDirectories[] = $relative;
                }
                if (!@rename($source, $target)) {
                    if (file_exists($backup) || is_link($backup)) {
                        @rename($backup, $target);
                    }
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cd73a4bb8609') . $relative . '.');
                }
            }

            foreach ($files as $relative) {
                $source = $releaseDir . '/' . $relative;
                if (!is_file($source)) {
                    if (in_array($relative, ['package-lock.json', 'yarn.lock', 'composer.lock', 'phpunit.xml.dist', 'vite.config.ts', 'tsconfig.json', 'public/setup.php', 'extensions/manifest.schema.json'], true)) {
                        continue;
                    }
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9bda68d552cd') . $relative . '.');
                }
                $target = $this->projectPath($relative);
                $backup = $quarantine . '/files/' . $relative;
                $this->ensureDirectory(dirname($target), 0750);
                $this->ensureDirectory(dirname($backup), 0750);
                if (is_file($target) || is_link($target)) {
                    if (!@rename($target, $backup)) {
                        throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.425e835e4969') . $relative . '.');
                    }
                    $this->swappedFiles[$relative] = $backup;
                } else {
                    $this->swappedFiles[$relative] = null;
                }
                $tmp = $target . '.update-' . bin2hex(random_bytes(4));
                if (!@copy($source, $tmp)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d4e4db48514d') . $relative . '.');
                }
                @chmod($tmp, 0644);
                if (!@rename($tmp, $target)) {
                    @unlink($tmp);
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ab8eb65884a9') . $relative . '.');
                }
            }

            return ['quarantine' => $quarantine, 'directories' => $this->swappedDirectories, 'files' => array_keys($this->swappedFiles)];
        } catch (Throwable $e) {
            $this->rollback($quarantine);
            throw $e;
        }
    }

    public function rollback(string $quarantine): void
    {
        foreach (array_reverse(array_keys($this->swappedFiles)) as $relative) {
            $target = $this->projectPath($relative);
            $backup = $this->swappedFiles[$relative];
            if (is_file($target) || is_link($target)) {
                @unlink($target);
            }
            if (is_string($backup) && (is_file($backup) || is_link($backup))) {
                $this->ensureDirectory(dirname($target), 0750);
                @rename($backup, $target);
            }
        }
        $this->swappedFiles = [];

        foreach (array_reverse($this->swappedDirectories) as $relative) {
            $target = $this->projectPath($relative);
            $backup = $quarantine . '/directories/' . $relative;
            $failed = $quarantine . '/failed-release/' . $relative;
            if (file_exists($target) || is_link($target)) {
                $this->ensureDirectory(dirname($failed), 0750);
                @rename($target, $failed);
            }
            if (file_exists($backup) || is_link($backup)) {
                $this->ensureDirectory(dirname($target), 0750);
                @rename($backup, $target);
            }
        }
        $this->swappedDirectories = [];
    }

    private function assertDependencySafety(string $releaseDir, bool $includeVendor): void
    {
        $currentLock = $this->projectPath('composer.lock');
        $nextLock = $releaseDir . '/composer.lock';
        if (is_file($currentLock) && is_file($nextLock) && hash_file('sha256', $currentLock) !== hash_file('sha256', $nextLock) && !$includeVendor) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4ae4b5080958'));
        }
        if (!is_file($releaseDir . '/public/assets/storefront.css')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0e753992218b'));
        }
    }

    private function projectPath(string $relative): string
    {
        return rtrim($this->projectDir, '/\\') . '/' . ltrim($relative, '/\\');
    }

    private function ensureDirectory(string $directory, int $mode): void
    {
        if (!is_dir($directory) && !@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.52dc18fcfb36'));
        }
    }
}
