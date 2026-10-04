<?php

declare(strict_types=1);

namespace Commerce\Core\Update;

use Doctrine\DBAL\Connection;

/** Database migrations that ship with the files but are not applied yet (the files are newer than the database). */
final class PendingMigrations
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir)
    {
    }

    /** @return list<string> */
    public function files(): array
    {
        return array_map(static fn (string $path): string => 'Commerce\\Migrations\\' . basename($path, '.php'), glob($this->projectDir . '/migrations/Version*.php') ?: []);
    }

    /** @return list<string> */
    public function applied(): array
    {
        try {
            return array_map('strval', $this->db->fetchFirstColumn('SELECT version FROM mc_migration_versions'));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff($this->files(), $this->applied()));
    }
}
