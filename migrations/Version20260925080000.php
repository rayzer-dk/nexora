<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Private digital product files and per-order download entitlements.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_product_digital_asset (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            storage_key VARCHAR(512) NOT NULL,
            mime_type VARCHAR(190) NOT NULL,
            bytes BIGINT UNSIGNED NOT NULL,
            checksum_sha256 BINARY(32) NOT NULL,
            max_downloads INT UNSIGNED NOT NULL DEFAULT 5,
            access_days INT UNSIGNED NULL DEFAULT 365,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_digital_asset_public_id (public_id),
            KEY idx_digital_asset_storage_key (storage_key),
            KEY idx_digital_asset_product_status (product_id,status,id),
            CONSTRAINT fk_digital_asset_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_digital_entitlement (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            asset_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            max_downloads INT UNSIGNED NOT NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            access_days INT UNSIGNED NULL,
            activated_at DATETIME(6) NULL,
            expires_at DATETIME(6) NULL,
            last_downloaded_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_digital_entitlement_public_id (public_id),
            UNIQUE KEY uq_digital_entitlement_item_asset (order_item_id,asset_id),
            KEY idx_digital_entitlement_customer (store_id,customer_id,status,created_at),
            KEY idx_digital_entitlement_order (order_id,status),
            CONSTRAINT fk_digital_entitlement_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE RESTRICT,
            CONSTRAINT fk_digital_entitlement_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE,
            CONSTRAINT fk_digital_entitlement_order_item FOREIGN KEY (order_item_id) REFERENCES mc_sales_order_item(id) ON DELETE CASCADE,
            CONSTRAINT fk_digital_entitlement_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL,
            CONSTRAINT fk_digital_entitlement_asset FOREIGN KEY (asset_id) REFERENCES mc_product_digital_asset(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_digital_entitlement');
        $this->addSql('DROP TABLE IF EXISTS mc_product_digital_asset');
    }
}
