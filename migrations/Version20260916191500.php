<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916191500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add VAT/tax policies, immutable tax snapshots, EU product compliance, documents and product relations.';
    }

    public function up(Schema $schema): void
    {
        $path = dirname(__DIR__) . '/resources/database/mysql/006_tax_product_compliance.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Cannot read schema v6 migration SQL.');
        }

        foreach ($this->statements($sql) as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Schema v6 contains compliance/tax history and is intentionally forward-only. Restore the pre-update checkpoint for rollback.');
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $statement): bool => $statement !== ''));
    }
}
