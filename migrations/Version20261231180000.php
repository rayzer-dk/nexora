<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FOP package: the single-tax group, the income limit of the year and the warning level of a shop run by a sole proprietor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mc_fop_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            tax_group TINYINT UNSIGNED NOT NULL DEFAULT 3,
            min_wage_minor BIGINT UNSIGNED NOT NULL DEFAULT 864700,
            limit_override_minor BIGINT UNSIGNED NULL,
            warn_percent TINYINT UNSIGNED NOT NULL DEFAULT 80,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_fop_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_fop_settings');
    }
}
