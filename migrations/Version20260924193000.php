<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add production promotions, coupons, redemption audit and order discount snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_promotion (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            code VARCHAR(64) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            trigger_type VARCHAR(32) NOT NULL DEFAULT 'automatic',
            discount_type VARCHAR(32) NOT NULL,
            discount_value BIGINT UNSIGNED NOT NULL,
            min_subtotal_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            max_discount_minor BIGINT UNSIGNED NULL,
            usage_limit INT UNSIGNED NULL,
            usage_count INT UNSIGNED NOT NULL DEFAULT 0,
            per_customer_limit INT UNSIGNED NULL,
            priority INT NOT NULL DEFAULT 100,
            stop_processing TINYINT(1) NOT NULL DEFAULT 0,
            conditions_json JSON NULL,
            starts_at DATETIME(6) NULL,
            ends_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_promotion_public_id (public_id),
            UNIQUE KEY uq_promotion_store_code (store_id, code),
            KEY idx_promotion_lookup (store_id, status, trigger_type, starts_at, ends_at, priority, id),
            CONSTRAINT fk_promotion_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_promotion_redemption (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            promotion_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            email_normalized VARCHAR(320) NULL,
            coupon_code VARCHAR(64) NULL,
            discount_minor BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_promotion_redemption_order (promotion_id, order_id),
            KEY idx_promotion_redemption_customer (promotion_id, customer_id, created_at),
            KEY idx_promotion_redemption_email (promotion_id, email_normalized, created_at),
            CONSTRAINT fk_promotion_redemption_promotion FOREIGN KEY (promotion_id) REFERENCES mc_promotion(id) ON DELETE RESTRICT,
            CONSTRAINT fk_promotion_redemption_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE,
            CONSTRAINT fk_promotion_redemption_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_order_promotion (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            promotion_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            coupon_code VARCHAR(64) NULL,
            discount_minor BIGINT UNSIGNED NOT NULL,
            snapshot_json JSON NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_order_promotion (order_id, promotion_id),
            KEY idx_order_promotion_promotion (promotion_id, order_id),
            CONSTRAINT fk_order_promotion_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE,
            CONSTRAINT fk_order_promotion_promotion FOREIGN KEY (promotion_id) REFERENCES mc_promotion(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_marketing_subscriber (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(320) NOT NULL,
            email_normalized VARCHAR(320) NOT NULL,
            locale VARCHAR(16) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            confirm_token_hash BINARY(32) NULL,
            consent_source VARCHAR(64) NOT NULL DEFAULT 'storefront',
            consent_at DATETIME(6) NOT NULL,
            confirmed_at DATETIME(6) NULL,
            unsubscribed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_marketing_subscriber_public_id (public_id),
            UNIQUE KEY uq_marketing_subscriber_store_email (store_id, email_normalized),
            KEY idx_marketing_subscriber_status (store_id, status, created_at),
            CONSTRAINT fk_marketing_subscriber_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_marketing_campaign (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_text TEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
            cursor_subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            enqueued_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_marketing_campaign_public_id (public_id),
            KEY idx_marketing_campaign_store (store_id, status, created_at),
            CONSTRAINT fk_marketing_campaign_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_marketing_campaign');
        $this->addSql('DROP TABLE IF EXISTS mc_marketing_subscriber');
        $this->addSql('DROP TABLE IF EXISTS mc_order_promotion');
        $this->addSql('DROP TABLE IF EXISTS mc_promotion_redemption');
        $this->addSql('DROP TABLE IF EXISTS mc_promotion');
    }
}
