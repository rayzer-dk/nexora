<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer email verification and password recovery tokens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_customer ADD email_verified_at DATETIME(6) NULL AFTER email_normalized');
        $this->addSql("CREATE TABLE mc_customer_account_token (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            purpose VARCHAR(32) NOT NULL,
            token_hash BINARY(32) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            consumed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_customer_account_token_hash (token_hash),
            KEY idx_customer_account_token_customer_purpose (customer_id, purpose, created_at),
            KEY idx_customer_account_token_expiry (purpose, expires_at, consumed_at),
            CONSTRAINT fk_customer_account_token_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_customer_account_token');
        $this->addSql('ALTER TABLE mc_customer DROP COLUMN email_verified_at');
    }
}
