<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use JsonException;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Performs dependency-light validation of a staged Core release before any live path is changed.
 * No PHP file from the candidate release is executed.
 */
final class StagedCoreReleaseValidator
{
    private const MAX_FILES = 60000;
    private const MAX_SINGLE_PHP_BYTES = 8_388_608;
    private const MAX_TOTAL_PHP_BYTES = 268_435_456;

    /**
     * @return array{php_files:int,json_files:int,yaml_files:int,total_php_bytes:int}
     */
    public function validate(string $releaseDir, string $expectedVersion): array
    {
        $releaseDir = rtrim($releaseDir, '/\\');
        if (!is_dir($releaseDir)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.de9b0a4ca25d'));
        }

        foreach ([
            'src', 'config', 'migrations', 'bootstrap', 'bin', 'resources', 'public/assets',
        ] as $directory) {
            if (!is_dir($releaseDir . '/' . $directory)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f4375bc802db') . $directory . '.');
            }
        }
        foreach ([
            'composer.json', 'public/index.php', 'bootstrap/emergency.php', 'resources/platform/release.json',
        ] as $file) {
            if (!is_file($releaseDir . '/' . $file)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3bd6173ae806') . $file . '.');
            }
        }

        $releaseMeta = $this->readJson($releaseDir . '/resources/platform/release.json');
        if ((string) ($releaseMeta['version'] ?? '') !== $expectedVersion) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fab2bf924423'));
        }

        $composer = $this->readJson($releaseDir . '/composer.json');
        if (!is_array($composer['require'] ?? null) || !isset($composer['require']['php'])) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.da8b7adaa2d8'));
        }

        $phpFiles = 0;
        $jsonFiles = 2;
        $yamlFiles = 0;
        $totalPhpBytes = 0;
        $seen = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($releaseDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $entry) {
            if (++$seen > self::MAX_FILES) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5c52d39d8198'));
            }
            if ($entry->isLink()) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c4766ced86b2'));
            }
            if (!$entry->isFile()) {
                continue;
            }

            $path = $entry->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($releaseDir) + 1));
            if ($relative === '' || str_contains('/' . $relative . '/', '/../') || str_contains($relative, "\0")) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f4e4dedf8db0'));
            }

            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if ($extension === 'php') {
                $size = $entry->getSize();
                if ($size < 1 || $size > self::MAX_SINGLE_PHP_BYTES) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ec6e4ad64b6a') . $relative . '.');
                }
                $totalPhpBytes += $size;
                if ($totalPhpBytes > self::MAX_TOTAL_PHP_BYTES) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0f25a018e36d'));
                }
                $code = @file_get_contents($path);
                if (!is_string($code)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ee29d03c0f60') . $relative . '.');
                }
                try {
                    token_get_all($code, TOKEN_PARSE);
                } catch (ParseError $e) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6c4cd4d4a029') . $relative . ': ' . $e->getMessage(), 0, $e);
                }
                $phpFiles++;
                continue;
            }

            if ($extension === 'json' && !in_array($relative, ['composer.json', 'resources/platform/release.json'], true)) {
                $this->readJson($path);
                $jsonFiles++;
                continue;
            }

            if (in_array($extension, ['yaml', 'yml'], true)) {
                try {
                    Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
                } catch (\Throwable $e) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.814217feb49e') . $relative . ': ' . $e->getMessage(), 0, $e);
                }
                $yamlFiles++;
            }
        }

        if ($phpFiles < 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.96e01bd81f65'));
        }
        if (!is_file($releaseDir . '/public/assets/storefront.css') || !is_file($releaseDir . '/public/assets/storefront-runtime.js')) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.85fe99aa238d'));
        }

        return [
            'php_files' => $phpFiles,
            'json_files' => $jsonFiles,
            'yaml_files' => $yamlFiles,
            'total_php_bytes' => $totalPhpBytes,
        ];
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cd9b6d1b9f85') . basename($path) . '.');
        }
        try {
            $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.a7139a4df98b') . $path . '.', 0, $e);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0c2d505a0605') . $path . '.');
        }
        return $decoded;
    }
}
