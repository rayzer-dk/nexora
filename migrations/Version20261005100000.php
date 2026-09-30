<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order fraud scoring and blocklist, trusted customer devices, product info blocks, article-product links.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql("CREATE TABLE mc_store_security_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            fraud_enabled TINYINT(1) NOT NULL DEFAULT 1,
            fraud_review_score SMALLINT UNSIGNED NOT NULL DEFAULT 40,
            fraud_high_score SMALLINT UNSIGNED NOT NULL DEFAULT 80,
            fraud_velocity_limit SMALLINT UNSIGNED NOT NULL DEFAULT 3,
            fraud_high_value_minor BIGINT UNSIGNED NOT NULL DEFAULT 5000000,
            device_confirm_enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_store_security_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_fraud_blocklist (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(16) NOT NULL,
            value VARCHAR(190) NOT NULL,
            note VARCHAR(190) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fraud_blocklist (store_id, kind, value),
            CONSTRAINT fk_fraud_blocklist_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_order_fraud (
            order_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            score SMALLINT UNSIGNED NOT NULL,
            level VARCHAR(12) NOT NULL,
            reasons JSON NOT NULL,
            ip_hash BINARY(32) NULL,
            decision VARCHAR(16) NULL,
            decided_by VARCHAR(190) NULL,
            decided_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (order_id),
            KEY idx_order_fraud_review (store_id, level, decision, created_at),
            KEY idx_order_fraud_ip (ip_hash, created_at),
            CONSTRAINT fk_order_fraud_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_customer_device (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            token_hash BINARY(32) NOT NULL,
            label VARCHAR(190) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            last_used_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_customer_device_token (token_hash),
            KEY idx_customer_device_customer (customer_id, expires_at),
            KEY idx_customer_device_expiry (expires_at),
            CONSTRAINT fk_customer_device_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_product_info_block (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NULL,
            position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            block_type VARCHAR(16) NOT NULL,
            title VARCHAR(190) NOT NULL DEFAULT '',
            payload JSON NOT NULL,
            PRIMARY KEY (id),
            KEY idx_product_info_block (product_id, position),
            CONSTRAINT fk_product_info_block_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_blog_article_product (
            content_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (content_id, product_id),
            KEY idx_blog_article_product_product (product_id),
            CONSTRAINT fk_blog_article_product_content FOREIGN KEY (content_id) REFERENCES mc_content_entry(id) ON DELETE CASCADE,
            CONSTRAINT fk_blog_article_product_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        )" . $t);
    }

    public function down(Schema $schema): void
    {
        foreach (['mc_blog_article_product', 'mc_product_info_block', 'mc_customer_device', 'mc_order_fraud', 'mc_fraud_blocklist', 'mc_store_security_settings'] as $table) {
            $this->addSql('DROP TABLE ' . $table);
        }
    }
}
