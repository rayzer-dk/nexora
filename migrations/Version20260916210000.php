<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add EU privacy/consent, consumer-rights, price-history, universal product compliance and accessibility data.';
    }

    public function up(Schema $schema): void
    {
        $path = dirname(__DIR__) . '/resources/database/mysql/007_eu_privacy_compliance.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Cannot read schema v7 migration SQL.');
        }

        foreach ($this->statements($sql) as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Schema v7 contains consent/legal/compliance history and is intentionally forward-only. Restore the pre-update checkpoint for rollback.');
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $statement): bool => $statement !== ''));
    }
}
