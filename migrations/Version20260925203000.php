<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Production Public API tokens, scopes, rate-limit windows and idempotency storage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_api_token (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            token_prefix VARCHAR(24) NOT NULL,
            token_hash BINARY(32) NOT NULL,
            scopes JSON NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            rate_limit_per_minute SMALLINT UNSIGNED NOT NULL DEFAULT 120,
            expires_at DATETIME(6) NULL,
            last_used_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            revoked_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_api_token_public_id (public_id),
            UNIQUE KEY uq_api_token_hash (token_hash),
            KEY idx_api_token_store_status (store_id,status),
            KEY idx_api_token_expiry (status,expires_at),
            CONSTRAINT fk_api_token_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_api_rate_window (
            token_id BIGINT UNSIGNED NOT NULL,
            window_started_at DATETIME NOT NULL,
            request_count INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (token_id,window_started_at),
            CONSTRAINT fk_api_rate_window_token FOREIGN KEY (token_id) REFERENCES mc_api_token(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_api_idempotency (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(190) NOT NULL,
            request_hash BINARY(32) NOT NULL,
            response_status SMALLINT UNSIGNED NOT NULL,
            response_body JSON NOT NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_api_idempotency_token_key (token_id,idempotency_key),
            KEY idx_api_idempotency_expiry (expires_at),
            CONSTRAINT fk_api_idempotency_token FOREIGN KEY (token_id) REFERENCES mc_api_token(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_api_idempotency');
        $this->addSql('DROP TABLE IF EXISTS mc_api_rate_window');
        $this->addSql('DROP TABLE IF EXISTS mc_api_token');
    }
}
