<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Executes a build-time generated, signed SQL migration plan.
 * The plan lives inside the signed update payload; arbitrary uploaded SQL is never accepted.
 */
final readonly class CoreUpdateMigrationRunner
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public function plannedVersions(string $releaseDir): array
    {
        $plan = $this->loadPlan($releaseDir);
        if ($plan === null) {
            return [];
        }
        return array_values(array_map(static fn (array $migration): string => (string) $migration['version'], $plan['migrations']));
    }

    /** @return array{migrations:int,statements:int} */
    public function run(string $releaseDir): array
    {
        $plan = $this->loadPlan($releaseDir);
        if ($plan === null) {
            return ['migrations' => 0, 'statements' => 0];
        }

        $statements = 0;
        foreach ($plan['migrations'] as $migration) {
            $started = microtime(true);
            foreach ($migration['statements'] as $sql) {
                $this->connection->executeStatement($sql);
                $statements++;
            }
            $this->markDoctrineMigration((string) $migration['version'], (int) round((microtime(true) - $started) * 1000));
        }

        return ['migrations' => count($plan['migrations']), 'statements' => $statements];
    }

    /** @return array{format:int,database:string,migrations:list<array{version:string,statements:list<string>}>}|null */
    private function loadPlan(string $releaseDir): ?array
    {
        $path = rtrim($releaseDir, '/\\') . '/update/migrations.json';
        if (!is_file($path)) {
            return null;
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > 10 * 1024 * 1024) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.99d60f9e233b'));
        }
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || (int) ($data['format'] ?? 0) !== 1 || !is_array($data['migrations'] ?? null)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8dac5f8265ad'));
        }
        $database = strtolower((string) ($data['database'] ?? 'mysql'));
        if (!in_array($database, ['mysql', 'mysql-mariadb'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c4310c007b4b'));
        }
        if (count($data['migrations']) > 200) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.16e4efb06466'));
        }

        $normalized = [];
        $seen = [];
        $totalStatements = 0;
        foreach ($data['migrations'] as $migration) {
            if (!is_array($migration)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.67fa42c5864d'));
            }
            $version = trim((string) ($migration['version'] ?? ''));
            if ($version === '' || strlen($version) > 255 || !preg_match('/^[A-Za-z0-9_\\\\.:-]+$/D', $version) || isset($seen[$version])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3c5042e76c9c'));
            }
            $seen[$version] = true;
            $sqlList = [];
            foreach ((array) ($migration['statements'] ?? []) as $statement) {
                $sql = trim((string) $statement);
                if ($sql === '' || strlen($sql) > 2 * 1024 * 1024) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5a6f90c9ad43'));
                }
                $this->assertSafeCoreSql($sql);
                $sqlList[] = $sql;
                $totalStatements++;
                if ($totalStatements > 1000) {
                    throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0cc2ff8f8bab'));
                }
            }
            $normalized[] = ['version' => $version, 'statements' => $sqlList];
        }

        return ['format' => 1, 'database' => $database, 'migrations' => $normalized];
    }

    private function assertSafeCoreSql(string $sql): void
    {
        $head = strtoupper((string) strtok(ltrim($sql), " \t\r\n"));
        if (!in_array($head, ['CREATE', 'ALTER', 'DROP', 'INSERT', 'UPDATE', 'DELETE', 'RENAME', 'TRUNCATE', 'SET'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.72607c138a51'));
        }
        if (preg_match('/\b(?:OUTFILE|DUMPFILE|LOAD\s+DATA|CREATE\s+USER|ALTER\s+USER|DROP\s+USER|GRANT\s+|REVOKE\s+|INSTALL\s+PLUGIN|UNINSTALL\s+PLUGIN|CREATE\s+PROCEDURE|CREATE\s+FUNCTION)\b/i', $sql)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5110dbe795d1'));
        }
    }

    private function markDoctrineMigration(string $version, int $executionMs): void
    {
        try {
            $exists = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='doctrine_migration_versions'");
            if ($exists !== 1) {
                return;
            }
            $this->connection->executeStatement(
                'INSERT IGNORE INTO doctrine_migration_versions (version,executed_at,execution_time) VALUES (?,NOW(),?)',
                [$version, max(0, $executionMs)],
            );
        } catch (\Throwable $e) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.8908e88db531'), 0, $e);
        }
    }
}
