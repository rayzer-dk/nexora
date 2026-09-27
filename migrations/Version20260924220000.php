<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purchase-state UX, stock notifications, customer inquiries, checkout notes/company fields and customer groups.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_customer ADD customer_group_code VARCHAR(64) NOT NULL DEFAULT 'default' AFTER status");
        $this->addSql('CREATE INDEX idx_customer_group_status ON mc_customer (customer_group_code,status,id)');

        $this->addSql("CREATE TABLE mc_product_purchase_policy (
            product_id BIGINT UNSIGNED NOT NULL,
            mode VARCHAR(32) NOT NULL DEFAULT 'auto',
            button_label VARCHAR(120) NULL,
            eta_text VARCHAR(190) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (product_id),
            KEY idx_product_purchase_policy_mode (mode,product_id),
            CONSTRAINT fk_product_purchase_policy_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE mc_stock_notification_request (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(320) NOT NULL,
            email_normalized VARCHAR(320) NOT NULL,
            locale VARCHAR(16) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            confirm_token_hash BINARY(32) NULL,
            confirmed_at DATETIME(6) NULL,
            notified_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_stock_notification_public_id (public_id),
            UNIQUE KEY uq_stock_notification_request (store_id,variant_id,email_normalized),
            KEY idx_stock_notification_variant_status (variant_id,status,id),
            KEY idx_stock_notification_email (store_id,email_normalized,status),
            CONSTRAINT fk_stock_notification_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_stock_notification_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
            CONSTRAINT fk_stock_notification_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE mc_customer_inquiry (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            inquiry_type VARCHAR(32) NOT NULL DEFAULT 'contact',
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            customer_name VARCHAR(190) NOT NULL,
            email VARCHAR(320) NULL,
            phone VARCHAR(64) NULL,
            message TEXT NOT NULL,
            source_url VARCHAR(1000) NULL,
            admin_note TEXT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_customer_inquiry_public_id (public_id),
            KEY idx_customer_inquiry_store_status (store_id,status,created_at,id),
            KEY idx_customer_inquiry_product (product_id,created_at),
            CONSTRAINT fk_customer_inquiry_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_customer_inquiry_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql('ALTER TABLE mc_sales_order ADD customer_comment VARCHAR(2000) NULL AFTER customer_name');
        $this->addSql('ALTER TABLE mc_sales_order ADD company_name VARCHAR(190) NULL AFTER customer_comment');
        $this->addSql('ALTER TABLE mc_sales_order ADD company_tax_id VARCHAR(64) NULL AFTER company_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sales_order DROP COLUMN company_tax_id');
        $this->addSql('ALTER TABLE mc_sales_order DROP COLUMN company_name');
        $this->addSql('ALTER TABLE mc_sales_order DROP COLUMN customer_comment');
        $this->addSql('DROP TABLE IF EXISTS mc_customer_inquiry');
        $this->addSql('DROP TABLE IF EXISTS mc_stock_notification_request');
        $this->addSql('DROP TABLE IF EXISTS mc_product_purchase_policy');
        $this->addSql('DROP INDEX idx_customer_group_status ON mc_customer');
        $this->addSql('ALTER TABLE mc_customer DROP COLUMN customer_group_code');
    }
}
