<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification channels edited in the admin: SMTP server and the order-alert Telegram bot (secrets encrypted).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_notification_channel_settings (
            store_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
            smtp_host VARCHAR(190) NOT NULL DEFAULT '',
            smtp_port INT UNSIGNED NOT NULL DEFAULT 587,
            smtp_encryption VARCHAR(8) NOT NULL DEFAULT 'tls',
            smtp_user VARCHAR(190) NOT NULL DEFAULT '',
            smtp_pass_enc TEXT NULL,
            from_address VARCHAR(190) NOT NULL DEFAULT '',
            from_name VARCHAR(120) NOT NULL DEFAULT '',
            tg_enabled TINYINT(1) NOT NULL DEFAULT 0,
            tg_token_enc TEXT NULL,
            tg_chat_id VARCHAR(32) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_notification_channel_settings');
    }
}
