<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reverse charge: a switch per store and the VAT taken off an order of a business buyer with a valid EU VAT number.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mc_vat_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            reverse_charge TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_vat_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE mc_sales_order ADD vat_relief_minor BIGINT UNSIGNED NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sales_order DROP COLUMN vat_relief_minor');
        $this->addSql('DROP TABLE mc_vat_settings');
    }
}
