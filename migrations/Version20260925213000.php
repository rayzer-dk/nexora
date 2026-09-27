<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store-scoped outbound API webhook subscriptions and durable signed delivery state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_webhook_subscription (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            target_url VARCHAR(2048) NOT NULL,
            target_url_hash BINARY(32) NOT NULL,
            secret_cipher TEXT NOT NULL,
            events JSON NOT NULL,
            creation_key_hash BINARY(32) NOT NULL,
            creation_request_hash BINARY(32) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            last_delivery_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            disabled_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_webhook_subscription_public (public_id),
            UNIQUE KEY uq_webhook_subscription_creation (store_id,creation_key_hash),
            KEY idx_webhook_subscription_store_status (store_id,status),
            CONSTRAINT fk_webhook_subscription_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("ALTER TABLE mc_webhook_delivery
            ADD COLUMN store_id BIGINT UNSIGNED NULL AFTER public_id,
            ADD COLUMN event_type VARCHAR(190) NULL AFTER event_id,
            ADD COLUMN payload JSON NULL AFTER event_type,
            ADD COLUMN locked_at DATETIME(6) NULL AFTER next_attempt_at,
            ADD COLUMN lock_token BINARY(16) NULL AFTER locked_at,
            ADD UNIQUE KEY uq_webhook_subscription_event (subscription_key,event_id),
            ADD KEY idx_webhook_stale_lock (status,locked_at,id),
            ADD KEY idx_webhook_store_created (store_id,created_at),
            ADD CONSTRAINT fk_webhook_delivery_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_webhook_delivery DROP FOREIGN KEY fk_webhook_delivery_store, DROP INDEX idx_webhook_store_created, DROP INDEX idx_webhook_stale_lock, DROP INDEX uq_webhook_subscription_event, DROP COLUMN lock_token, DROP COLUMN locked_at, DROP COLUMN payload, DROP COLUMN event_type, DROP COLUMN store_id');
        $this->addSql('DROP TABLE IF EXISTS mc_webhook_subscription');
    }
}
