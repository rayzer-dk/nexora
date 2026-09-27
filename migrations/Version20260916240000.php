<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Installation state, administrator identities and store legal/contact profile.';
    }

    public function up(Schema $schema): void
    {
        $path = dirname(__DIR__) . '/resources/database/mysql/010_installation_admin_runtime.sql';
        $sql = @file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Cannot read schema v10 migration SQL.');
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Schema v10 is forward-only. Restore the pre-update checkpoint for rollback.');
    }
}
