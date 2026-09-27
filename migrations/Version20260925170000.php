<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925170000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add gift cards, loyalty accounts, immutable ledgers and order benefit snapshots.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_gift_card (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            code_hash CHAR(64) NOT NULL,
            code_last4 VARCHAR(4) NOT NULL,
            currency CHAR(3) NOT NULL,
            initial_minor BIGINT UNSIGNED NOT NULL,
            balance_minor BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            expires_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(id), UNIQUE KEY uq_gift_card_public(public_id), UNIQUE KEY uq_gift_card_code(store_id,code_hash),
            KEY idx_gift_card_customer(store_id,customer_id,status),
            CONSTRAINT fk_gift_card_store FOREIGN KEY(store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_gift_card_customer FOREIGN KEY(customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_gift_card_transaction (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gift_card_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            tx_type VARCHAR(32) NOT NULL,
            amount_minor BIGINT NOT NULL,
            balance_after_minor BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(190) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(id), UNIQUE KEY uq_gift_card_tx_key(idempotency_key), KEY idx_gift_card_tx_card(gift_card_id,created_at),
            CONSTRAINT fk_gift_card_tx_card FOREIGN KEY(gift_card_id) REFERENCES mc_gift_card(id) ON DELETE CASCADE,
            CONSTRAINT fk_gift_card_tx_order FOREIGN KEY(order_id) REFERENCES mc_sales_order(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_loyalty_config (
            store_id BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            earn_points_per_major INT UNSIGNED NOT NULL DEFAULT 1,
            redeem_minor_per_point INT UNSIGNED NOT NULL DEFAULT 1,
            min_redeem_points INT UNSIGNED NOT NULL DEFAULT 100,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(store_id), CONSTRAINT fk_loyalty_config_store FOREIGN KEY(store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_loyalty_account (
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            points_balance BIGINT UNSIGNED NOT NULL DEFAULT 0,
            lifetime_earned BIGINT UNSIGNED NOT NULL DEFAULT 0,
            lifetime_spent BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(store_id,customer_id), KEY idx_loyalty_customer(customer_id,store_id),
            CONSTRAINT fk_loyalty_account_store FOREIGN KEY(store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_loyalty_account_customer FOREIGN KEY(customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_loyalty_transaction (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            tx_type VARCHAR(32) NOT NULL,
            points BIGINT NOT NULL,
            balance_after BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(190) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY(id), UNIQUE KEY uq_loyalty_tx_key(idempotency_key), KEY idx_loyalty_tx_account(store_id,customer_id,created_at),
            CONSTRAINT fk_loyalty_tx_store FOREIGN KEY(store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_loyalty_tx_customer FOREIGN KEY(customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE,
            CONSTRAINT fk_loyalty_tx_order FOREIGN KEY(order_id) REFERENCES mc_sales_order(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("ALTER TABLE mc_sales_order ADD gift_card_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_minor, ADD gift_card_last4 VARCHAR(4) NULL AFTER gift_card_minor, ADD loyalty_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER gift_card_last4, ADD loyalty_points_spent BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER loyalty_minor");
        $this->addSql("INSERT IGNORE INTO mc_admin_permission(code,description,risk_level) VALUES ('rewards.view','View gift cards and loyalty','low'),('rewards.manage','Manage gift cards and loyalty','medium')");
        $this->addSql("INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES ('ROLE_VIEWER','rewards.view'),('ROLE_MANAGER','rewards.view'),('ROLE_MANAGER','rewards.manage')");
        $this->addSql("INSERT IGNORE INTO mc_loyalty_config(store_id,enabled,earn_points_per_major,redeem_minor_per_point,min_redeem_points,updated_at) SELECT id,1,1,1,100,UTC_TIMESTAMP(6) FROM mc_store");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM mc_admin_role_permission WHERE permission_code IN ('rewards.view','rewards.manage')");
        $this->addSql("DELETE FROM mc_admin_permission WHERE code IN ('rewards.view','rewards.manage')");
        $this->addSql('ALTER TABLE mc_sales_order DROP COLUMN loyalty_points_spent, DROP COLUMN loyalty_minor, DROP COLUMN gift_card_last4, DROP COLUMN gift_card_minor');
        $this->addSql('DROP TABLE IF EXISTS mc_loyalty_transaction');
        $this->addSql('DROP TABLE IF EXISTS mc_loyalty_account');
        $this->addSql('DROP TABLE IF EXISTS mc_loyalty_config');
        $this->addSql('DROP TABLE IF EXISTS mc_gift_card_transaction');
        $this->addSql('DROP TABLE IF EXISTS mc_gift_card');
    }
}
