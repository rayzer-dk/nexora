<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Runs reversible migrations for trusted extensions.
 *
 * Migration files MUST return:
 *   ['up' => static function(Connection $db): void {},
 *    'down' => static function(Connection $db): void {}]
 *
 * Nexora is still pre-release, so the former one-way callable contract is intentionally
 * not supported. Every trusted migration must be rollback-safe before public release.
 */
final readonly class ExtensionMigrationRunner
{
    public function __construct(private Connection $connection) {}

    /**
     * @param array<string,mixed> $manifest
     * @return list<string> migration keys newly applied during this call
     */
    public function apply(int $installationId, string $installPath, array $manifest): array
    {
        if ((string) ($manifest['execution'] ?? '') !== 'trusted_release') {
            return [];
        }

        $applied = [];
        foreach ((array) ($manifest['migrations'] ?? []) as $relative) {
            if (!is_string($relative) || $relative === '') {
                continue;
            }
            [$file, $relative] = $this->resolveMigration($installPath, $relative);
            $checksum = hash_file('sha256', $file);
            if (!is_string($checksum)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.b3fb03f5bc43'));
            }
            $existing = $this->connection->fetchAssociative(
                'SELECT checksum_sha256 FROM mc_extension_migration WHERE installation_id=? AND migration_key=? LIMIT 1',
                [$installationId, $relative]
            );
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['checksum_sha256'], $checksum)) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.e002ccbcb1f0') . $relative);
                }
                continue;
            }

            $definition = $this->loadDefinition($file, $relative);
            $definition['up']($this->connection);
            $this->connection->insert('mc_extension_migration', [
                'installation_id' => $installationId,
                'migration_key' => $relative,
                'checksum_sha256' => $checksum,
                'applied_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
            $applied[] = $relative;
        }

        return $applied;
    }

    /**
     * Compensates only migrations newly applied during a failed activation.
     *
     * @param array<string,mixed> $manifest
     * @param list<string> $migrationKeys
     */
    public function rollbackApplied(int $installationId, string $installPath, array $manifest, array $migrationKeys): void
    {
        if ($migrationKeys === []) {
            return;
        }
        $wanted = array_fill_keys($migrationKeys, true);
        $ordered = array_values(array_filter((array) ($manifest['migrations'] ?? []), static fn(mixed $v): bool => is_string($v) && isset($wanted[$v])));
        foreach (array_reverse($ordered) as $relative) {
            $this->rollbackOne($installationId, $installPath, (string) $relative);
        }
    }

    /** @param array<string,mixed> $manifest */
    public function rollbackInstallation(int $installationId, string $installPath, array $manifest): void
    {
        if ((string) ($manifest['execution'] ?? '') !== 'trusted_release') {
            return;
        }
        $migrations = array_values(array_filter((array) ($manifest['migrations'] ?? []), 'is_string'));
        foreach (array_reverse($migrations) as $relative) {
            $this->rollbackOne($installationId, $installPath, (string) $relative);
        }
    }

    private function rollbackOne(int $installationId, string $installPath, string $relative): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT checksum_sha256 FROM mc_extension_migration WHERE installation_id=? AND migration_key=? LIMIT 1',
            [$installationId, $relative]
        );
        if (!is_array($row)) {
            return;
        }
        [$file, $relative] = $this->resolveMigration($installPath, $relative);
        $checksum = hash_file('sha256', $file);
        if (!is_string($checksum) || !hash_equals((string) $row['checksum_sha256'], $checksum)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.e002ccbcb1f0') . $relative);
        }
        $definition = $this->loadDefinition($file, $relative);
        $definition['down']($this->connection);
        $this->connection->delete('mc_extension_migration', [
            'installation_id' => $installationId,
            'migration_key' => $relative,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function resolveMigration(string $installPath, string $relative): array
    {
        $relative = str_replace('\\', '/', $relative);
        $file = realpath($installPath . '/' . $relative);
        $root = realpath($installPath);
        if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.f5d8a7b7a911'));
        }
        return [$file, $relative];
    }

    /** @return array{up:callable,down:callable} */
    private function loadDefinition(string $file, string $relative): array
    {
        $definition = require $file;
        if (!is_array($definition) || !isset($definition['up'], $definition['down']) || !is_callable($definition['up']) || !is_callable($definition['down'])) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.runtime.reversible_migration_required') . $relative);
        }
        return ['up' => $definition['up'], 'down' => $definition['down']];
    }
}
