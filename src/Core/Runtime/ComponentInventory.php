<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

use Commerce\Core\Platform\PlatformVersion;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Throwable;

final class ComponentInventory
{
    public function __construct(
        private readonly string $projectDir,
        private readonly ?Connection $connection = null,
    ) {
    }

    public function collect(): array
    {
        $composer = $this->readJson($this->projectDir . '/composer.json');
        $package = $this->readJson($this->projectDir . '/package.json');

        $extensions = [];
        foreach (['curl', 'fileinfo', 'intl', 'json', 'mbstring', 'openssl', 'pdo', 'sodium'] as $extension) {
            $extensions[$extension] = extension_loaded($extension)
                ? (phpversion($extension) ?: 'built-in')
                : 'missing';
        }

        $composerRows = [];
        foreach (($composer['require'] ?? []) as $name => $constraint) {
            if ($name === 'php' || str_starts_with($name, 'ext-')) {
                continue;
            }

            $installed = 'not-installed';
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($name)) {
                $installed = InstalledVersions::getPrettyVersion($name) ?? 'installed';
            }

            $composerRows[] = [
                'name' => (string) $name,
                'required' => (string) $constraint,
                'installed' => $installed,
            ];
        }

        $frontend = [];
        foreach (($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []) as $name => $constraint) {
            $frontend[] = ['name' => (string) $name, 'required' => (string) $constraint];
        }

        $database = ['driver' => 'unavailable', 'version' => 'unavailable'];
        try {
            if ($this->connection !== null) {
                $database = [
                    'driver' => $this->connection->getDriver()::class,
                    'version' => (string) $this->connection->fetchOne('SELECT VERSION()'),
                ];
            }
        } catch (Throwable) {
            // Component Center must remain available during an unhealthy database state.
        }

        return [
            'platform' => [
                'version' => PlatformVersion::VERSION,
                'channel' => PlatformVersion::CHANNEL,
                'extension_api' => PlatformVersion::EXTENSION_API,
                'php_range' => sprintf('>=%s <%s', PlatformVersion::MIN_PHP, PlatformVersion::MAX_PHP_EXCLUSIVE),
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
            'database' => $database,
            'php_extensions' => $extensions,
            'composer' => $composerRows,
            'frontend' => $frontend,
        ];
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
