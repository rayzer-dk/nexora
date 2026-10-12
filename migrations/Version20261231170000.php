<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marketplaces: orders from a marketplace become shop orders once, stock and price are pushed as changed, order statuses go back.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql('CREATE TABLE mc_marketplace_order (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR(32) NOT NULL,
            external_id VARCHAR(64) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            pushed_status VARCHAR(24) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_marketplace_order (channel, external_id),
            KEY idx_marketplace_order_order (order_id),
            CONSTRAINT fk_marketplace_order_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE
        )' . $t);
        $this->addSql('CREATE TABLE mc_marketplace_stock (
            channel VARCHAR(32) NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            pushed_qty INT NOT NULL,
            pushed_price_minor BIGINT UNSIGNED NULL,
            pushed_at DATETIME(6) NOT NULL,
            PRIMARY KEY (channel, variant_id)
        )' . $t);
        $this->addSql('CREATE TABLE mc_marketplace_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR(32) NOT NULL,
            level VARCHAR(8) NOT NULL,
            message VARCHAR(500) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_marketplace_log (channel, id)
        )' . $t);
        $this->addSql('CREATE TABLE mc_marketplace_state (
            channel VARCHAR(32) NOT NULL,
            last_orders_at DATETIME(6) NULL,
            last_run_at DATETIME(6) NULL,
            imported_total INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            PRIMARY KEY (channel)
        )' . $t);
    }

    public function down(Schema $schema): void
    {
        foreach (['mc_marketplace_state', 'mc_marketplace_log', 'mc_marketplace_stock', 'mc_marketplace_order'] as $table) {
            $this->addSql('DROP TABLE ' . $table);
        }
    }
}
