<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum privacy profiles, private messaging, blocking, DM reports and contact verification codes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_customer ADD phone_verified_at DATETIME(6) NULL AFTER email_verified_at");

        $this->addSql("CREATE TABLE mc_customer_verification_code (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(16) NOT NULL,
            code_hash BINARY(32) NOT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME(6) NOT NULL,
            consumed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_customer_verification_code_lookup (customer_id, channel, consumed_at, expires_at, id),
            CONSTRAINT fk_customer_verification_code_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_profile (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            nickname VARCHAR(64) NOT NULL,
            bio VARCHAR(500) NULL,
            show_email TINYINT(1) NOT NULL DEFAULT 0,
            show_phone TINYINT(1) NOT NULL DEFAULT 0,
            allow_private_messages TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_profile_store_customer (store_id, customer_id),
            UNIQUE KEY uq_forum_profile_store_nickname (store_id, nickname),
            KEY idx_forum_profile_nickname (store_id, nickname),
            CONSTRAINT fk_forum_profile_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_profile_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_block (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            blocker_customer_id BIGINT UNSIGNED NOT NULL,
            blocked_customer_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_block (store_id, blocker_customer_id, blocked_customer_id),
            KEY idx_forum_block_blocked (store_id, blocked_customer_id),
            CONSTRAINT fk_forum_block_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_block_blocker FOREIGN KEY (blocker_customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_block_blocked FOREIGN KEY (blocked_customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_dm_thread (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_low_id BIGINT UNSIGNED NOT NULL,
            customer_high_id BIGINT UNSIGNED NOT NULL,
            last_message_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_dm_thread_public (public_id),
            UNIQUE KEY uq_forum_dm_thread_pair (store_id, customer_low_id, customer_high_id),
            KEY idx_forum_dm_thread_low (store_id, customer_low_id, last_message_at),
            KEY idx_forum_dm_thread_high (store_id, customer_high_id, last_message_at),
            CONSTRAINT fk_forum_dm_thread_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_dm_thread_low FOREIGN KEY (customer_low_id) REFERENCES mc_customer(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_dm_thread_high FOREIGN KEY (customer_high_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_dm_message (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            sender_customer_id BIGINT UNSIGNED NOT NULL,
            body_text TEXT NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'sent',
            created_at DATETIME(6) NOT NULL,
            read_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            KEY idx_forum_dm_message_thread (thread_id, id),
            KEY idx_forum_dm_message_sender_created (sender_customer_id, created_at),
            CONSTRAINT fk_forum_dm_message_thread FOREIGN KEY (thread_id) REFERENCES mc_forum_dm_thread(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_dm_message_sender FOREIGN KEY (sender_customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_dm_report (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            reporter_customer_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(32) NOT NULL,
            details VARCHAR(1000) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'open',
            created_at DATETIME(6) NOT NULL,
            resolved_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_dm_report (message_id, reporter_customer_id),
            KEY idx_forum_dm_report_store_status (store_id, status, created_at),
            CONSTRAINT fk_forum_dm_report_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_dm_report_message FOREIGN KEY (message_id) REFERENCES mc_forum_dm_message(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_dm_report_customer FOREIGN KEY (reporter_customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_forum_dm_report');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_dm_message');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_dm_thread');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_block');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_profile');
        $this->addSql('DROP TABLE IF EXISTS mc_customer_verification_code');
        $this->addSql('ALTER TABLE mc_customer DROP COLUMN phone_verified_at');
    }
}
