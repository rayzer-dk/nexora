<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Simplify Core, remove niche recall/traceability/energy/DPP storage, and standardize English system URLs.';
    }

    public function up(Schema $schema): void
    {
        $path = dirname(__DIR__) . '/resources/database/mysql/009_core_simplification_information_pages.sql';
        $sql = @file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Cannot read schema v9 migration SQL.');
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Schema v9 is forward-only. Restore the pre-update checkpoint for rollback.');
    }
}
