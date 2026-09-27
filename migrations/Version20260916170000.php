<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add central SEO route/redirect history, Ukraine-first store defaults and reservation commit timestamp.';
    }

    public function up(Schema $schema): void
    {
        if (!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform)) {
            throw new RuntimeException('Nexora Commerce supports MySQL/MariaDB for this migration.');
        }

        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist(['mc_seo_redirect']) && !$schemaManager->tablesExist(['mc_seo_route'])) {
            $this->addSql('ALTER TABLE mc_seo_redirect DROP FOREIGN KEY fk_seo_redirect_store');
            $this->addSql('RENAME TABLE mc_seo_redirect TO mc_seo_redirect_legacy');
        }

        $path = dirname(__DIR__) . '/resources/database/mysql/004_seo_routing_defaults.sql';
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->addSql($statement);
            }
        }
    }

    public function down(Schema $schema): void
    {
        throw new RuntimeException('Schema v4 is forward-only. Restore the pre-update checkpoint to roll back safely.');
    }
}
