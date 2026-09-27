<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916154500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add multilang/multicurrency registries, brand entities, Google taxonomy mapping and resumable Migration Center tables.';
    }

    public function up(Schema $schema): void
    {
        if (!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform)) {
            throw new RuntimeException('Nexora Commerce supports MySQL/MariaDB for this migration.');
        }

        $path = dirname(__DIR__) . '/resources/database/mysql/002_localization_migration_google.sql';
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
        $this->addSql('ALTER TABLE mc_product DROP FOREIGN KEY fk_product_brand');
        $this->addSql('ALTER TABLE mc_product DROP INDEX idx_product_brand_id');
        $this->addSql('ALTER TABLE mc_product DROP COLUMN brand_id');
        foreach ([
            'mc_import_issue', 'mc_import_id_map', 'mc_import_item', 'mc_import_job',
            'mc_google_product_override', 'mc_google_category_mapping',
            'mc_brand_translation', 'mc_store_brand', 'mc_brand',
            'mc_exchange_rate', 'mc_store_currency', 'mc_currency',
            'mc_store_locale', 'mc_locale',
        ] as $table) {
            $this->addSql('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
