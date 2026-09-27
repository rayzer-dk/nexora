<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make clean ASCII public URLs mandatory and make price-only the default B2C tax display.';
    }

    public function up(Schema $schema): void
    {
        $path = dirname(__DIR__) . '/resources/database/mysql/008_clean_urls_price_display.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Cannot read schema v8 migration SQL.');
        }
        foreach ($this->statements($sql) as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Schema v8 is forward-only. Restore the pre-update checkpoint for rollback.');
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $statement): bool => $statement !== ''));
    }
}
