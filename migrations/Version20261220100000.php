<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261220100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppliers: price/stock feeds, sync runs and the reviewed changes.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_supplier (
            id BIGINT AUTO_INCREMENT NOT NULL,
            store_id BIGINT NOT NULL,
            name VARCHAR(190) NOT NULL,
            feed_url VARCHAR(1000) NOT NULL,
            format VARCHAR(16) NOT NULL DEFAULT 'yml',
            http_user VARCHAR(190) NULL,
            http_pass_enc TEXT NULL,
            match_by VARCHAR(16) NOT NULL DEFAULT 'sku',
            sku_prefix VARCHAR(40) NOT NULL DEFAULT '',
            markup_percent DECIMAL(7,2) NOT NULL DEFAULT 0,
            rounding VARCHAR(16) NOT NULL DEFAULT 'none',
            apply_price TINYINT(1) NOT NULL DEFAULT 1,
            apply_stock TINYINT(1) NOT NULL DEFAULT 1,
            stock_when_available INT NOT NULL DEFAULT 5,
            mode VARCHAR(16) NOT NULL DEFAULT 'preview',
            interval_minutes INT NOT NULL DEFAULT 360,
            mapping_json LONGTEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_run_at DATETIME(6) NULL,
            last_status VARCHAR(24) NULL,
            last_message VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_supplier_store (store_id, enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_supplier_run (
            id BIGINT AUTO_INCREMENT NOT NULL,
            supplier_id BIGINT NOT NULL,
            started_at DATETIME(6) NOT NULL,
            finished_at DATETIME(6) NULL,
            rows_total INT NOT NULL DEFAULT 0,
            matched INT NOT NULL DEFAULT 0,
            changed INT NOT NULL DEFAULT 0,
            applied INT NOT NULL DEFAULT 0,
            new_total INT NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'running',
            message VARCHAR(500) NULL,
            PRIMARY KEY (id),
            INDEX idx_supplier_run (supplier_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_supplier_item (
            id BIGINT AUTO_INCREMENT NOT NULL,
            supplier_id BIGINT NOT NULL,
            run_id BIGINT NOT NULL,
            external_sku VARCHAR(190) NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            variant_id BIGINT NULL,
            product_name VARCHAR(255) NULL,
            action VARCHAR(16) NOT NULL,
            old_price_minor BIGINT NULL,
            new_price_minor BIGINT NULL,
            old_stock DECIMAL(18,6) NULL,
            new_stock DECIMAL(18,6) NULL,
            supplier_price_minor BIGINT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_supplier_item_run (supplier_id, status, action, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_supplier_item');
        $this->addSql('DROP TABLE mc_supplier_run');
        $this->addSql('DROP TABLE mc_supplier');
    }
}
