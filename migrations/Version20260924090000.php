<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Google Commerce and consent-aware Marketing Hub delivery state, diagnostics and retry indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_google_merchant_product_state (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            merchant_account VARCHAR(64) NOT NULL,
            content_language VARCHAR(16) NOT NULL,
            feed_label VARCHAR(32) NOT NULL,
            offer_id VARCHAR(190) NOT NULL,
            product_input_name VARCHAR(768) NULL,
            processed_product_name VARCHAR(768) NULL,
            sync_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            issues_json JSON NOT NULL,
            last_payload_hash BINARY(32) NULL,
            last_synced_at DATETIME(6) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_google_merchant_product (store_id, product_id, merchant_account, content_language, feed_label, offer_id),
            KEY idx_google_merchant_status (sync_status, updated_at),
            CONSTRAINT fk_google_merchant_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_google_merchant_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_marketing_delivery (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(32) NOT NULL,
            event_id VARCHAR(64) NOT NULL,
            event_name VARCHAR(128) NOT NULL,
            aggregate_type VARCHAR(64) NOT NULL,
            aggregate_id VARCHAR(190) NOT NULL,
            consent_scope VARCHAR(24) NOT NULL,
            status VARCHAR(24) NOT NULL,
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            response_code SMALLINT UNSIGNED NULL,
            last_error VARCHAR(1000) NULL,
            sent_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_marketing_delivery_provider_event (provider, event_id),
            KEY idx_marketing_delivery_status (provider, status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql('ALTER TABLE mc_integration_sync_queue ADD KEY idx_integration_sync_retry (status, available_at, attempts, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_integration_sync_queue DROP INDEX idx_integration_sync_retry');
        $this->addSql('DROP TABLE IF EXISTS mc_marketing_delivery');
        $this->addSql('DROP TABLE IF EXISTS mc_google_merchant_product_state');
    }
}
