<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support chat: Telegram bot settings, conversations (website and Telegram) and their messages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_support_chat_settings (
            store_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            site_chat_enabled TINYINT(1) NOT NULL DEFAULT 1,
            direct_enabled TINYINT(1) NOT NULL DEFAULT 1,
            bot_token_enc TEXT NULL,
            bot_username VARCHAR(64) NOT NULL DEFAULT '',
            group_chat_id VARCHAR(32) NOT NULL DEFAULT '',
            webhook_secret VARCHAR(64) NOT NULL DEFAULT '',
            welcome_text VARCHAR(500) NOT NULL DEFAULT '',
            offline_text VARCHAR(500) NOT NULL DEFAULT '',
            seen_chats TEXT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_support_thread (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(12) NOT NULL,
            public_token CHAR(32) NOT NULL,
            telegram_user_id BIGINT NULL,
            tg_topic_id BIGINT NULL,
            customer_name VARCHAR(120) NOT NULL DEFAULT '',
            customer_contact VARCHAR(190) NOT NULL DEFAULT '',
            customer_user_id BIGINT UNSIGNED NULL,
            source_url VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL,
            last_message_at DATETIME NOT NULL,
            UNIQUE KEY uq_support_thread_token (public_token),
            KEY idx_support_thread_tg_user (store_id, channel, telegram_user_id),
            KEY idx_support_thread_topic (store_id, tg_topic_id),
            KEY idx_support_thread_recent (store_id, last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_support_message (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            direction VARCHAR(4) NOT NULL,
            body TEXT NOT NULL,
            tg_message_id BIGINT NULL,
            tg_update_id BIGINT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_support_message_thread (thread_id, id),
            UNIQUE KEY uq_support_message_update (tg_update_id),
            CONSTRAINT fk_support_message_thread FOREIGN KEY (thread_id) REFERENCES mc_support_thread(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_support_message');
        $this->addSql('DROP TABLE mc_support_thread');
        $this->addSql('DROP TABLE mc_support_chat_settings');
    }
}
