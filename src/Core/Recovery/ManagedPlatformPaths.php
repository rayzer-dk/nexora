<?php

declare(strict_types=1);

namespace Commerce\Core\Recovery;

/**
 * Canonical list of platform-owned paths.
 *
 * Recovery snapshots intentionally include a wider set than core updates. Core updates
 * must never overwrite runtime secrets, user uploads or third-party extension storage.
 */
final class ManagedPlatformPaths
{
    /** @return list<string> */
    public static function recoveryDirectories(bool $includeVendor = true): array
    {
        $paths = [
            'src',
            'config',
            'migrations',
            'themes',
            'assets',
            'bootstrap',
            'bin',
            'resources',
            'extensions',
            'public/assets',
        ];

        if ($includeVendor) {
            $paths[] = 'vendor';
        }

        return $paths;
    }

    /** @return list<string> */
    public static function recoveryFiles(): array
    {
        return [
            '.env', '.env.local', '.env.prod', '.env.prod.local', '.htaccess',
            'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'yarn.lock',
            'phpunit.xml.dist', 'vite.config.ts', 'tsconfig.json', 'public/index.php', 'public/setup.php',
        ];
    }

    /**
     * Directories that a signed Core update is allowed to replace.
     * Custom themes and extension installations live outside these roots and survive Core updates.
     *
     * @return list<string>
     */
    public static function updateDirectories(bool $includeVendor): array
    {
        $paths = [
            'src',
            'config',
            'migrations',
            'themes/default',
            'assets',
            'bootstrap',
            'bin',
            'resources',
            'public/assets',
        ];

        if ($includeVendor) {
            $paths[] = 'vendor';
        }

        return $paths;
    }

    /** @return list<string> */
    public static function updateFiles(): array
    {
        return [
            'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'yarn.lock',
            'phpunit.xml.dist', 'vite.config.ts', 'tsconfig.json',
            'public/index.php', 'public/setup.php', 'extensions/manifest.schema.json',
        ];
    }

    private function __construct()
    {
    }
}
