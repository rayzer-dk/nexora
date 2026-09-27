<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add consent-safe marketing automations, campaign segments and order attribution snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_marketing_campaign ADD segment_code VARCHAR(64) NOT NULL DEFAULT 'all_subscribers' AFTER body_text");
        $this->addSql("CREATE TABLE mc_marketing_automation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            automation_type VARCHAR(32) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            delay_hours INT UNSIGNED NOT NULL DEFAULT 24,
            subject VARCHAR(255) NOT NULL,
            body_text TEXT NOT NULL,
            coupon_code VARCHAR(64) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_marketing_automation_store_type (store_id,automation_type),
            KEY idx_marketing_automation_enabled (store_id,enabled,automation_type),
            CONSTRAINT fk_marketing_automation_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_marketing_automation_delivery (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            automation_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            cart_id BIGINT UNSIGNED NULL,
            order_id BIGINT UNSIGNED NULL,
            recipient VARCHAR(320) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            dedupe_key VARCHAR(190) NOT NULL,
            recovery_token_hash BINARY(32) NULL,
            recovery_expires_at DATETIME(6) NULL,
            redeemed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            sent_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_marketing_automation_delivery_public_id (public_id),
            UNIQUE KEY uq_marketing_automation_delivery_dedupe (dedupe_key),
            KEY idx_marketing_automation_delivery_store (store_id,automation_id,created_at),
            KEY idx_marketing_automation_delivery_customer (customer_id,created_at),
            CONSTRAINT fk_marketing_auto_delivery_auto FOREIGN KEY (automation_id) REFERENCES mc_marketing_automation(id) ON DELETE CASCADE,
            CONSTRAINT fk_marketing_auto_delivery_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_marketing_auto_delivery_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL,
            CONSTRAINT fk_marketing_auto_delivery_cart FOREIGN KEY (cart_id) REFERENCES mc_cart(id) ON DELETE SET NULL,
            CONSTRAINT fk_marketing_auto_delivery_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_order_attribution (
            order_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            first_source VARCHAR(190) NULL,
            first_medium VARCHAR(190) NULL,
            first_campaign VARCHAR(190) NULL,
            last_source VARCHAR(190) NULL,
            last_medium VARCHAR(190) NULL,
            last_campaign VARCHAR(190) NULL,
            last_content VARCHAR(190) NULL,
            last_term VARCHAR(190) NULL,
            landing_url VARCHAR(1000) NULL,
            referrer_url VARCHAR(1000) NULL,
            captured_at DATETIME(6) NOT NULL,
            PRIMARY KEY (order_id),
            KEY idx_order_attribution_store_campaign (store_id,last_campaign,captured_at),
            KEY idx_order_attribution_store_source (store_id,last_source,last_medium,captured_at),
            CONSTRAINT fk_order_attribution_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE,
            CONSTRAINT fk_order_attribution_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_order_attribution');
        $this->addSql('DROP TABLE IF EXISTS mc_marketing_automation_delivery');
        $this->addSql('DROP TABLE IF EXISTS mc_marketing_automation');
        $this->addSql('ALTER TABLE mc_marketing_campaign DROP COLUMN segment_code');
    }
}
