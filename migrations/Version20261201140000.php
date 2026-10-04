<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SMS to customers: gateway settings, automatic triggers with templates, and the log of every SMS.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_sms_settings (
            store_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            endpoint VARCHAR(500) NOT NULL DEFAULT '',
            token_enc TEXT NULL,
            sender VARCHAR(32) NOT NULL DEFAULT '',
            flash_supported TINYINT(1) NOT NULL DEFAULT 0,
            auto_placed TINYINT(1) NOT NULL DEFAULT 0,
            auto_shipped TINYINT(1) NOT NULL DEFAULT 0,
            auto_ready TINYINT(1) NOT NULL DEFAULT 0,
            auto_cancelled TINYINT(1) NOT NULL DEFAULT 0,
            tpl_placed VARCHAR(500) NOT NULL DEFAULT '',
            tpl_shipped VARCHAR(500) NOT NULL DEFAULT '',
            tpl_ready VARCHAR(500) NOT NULL DEFAULT '',
            tpl_cancelled VARCHAR(500) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_sms_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_sms_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            recipient VARCHAR(20) NOT NULL,
            mode VARCHAR(8) NOT NULL,
            event VARCHAR(16) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            segments SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            flash TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(8) NOT NULL,
            error VARCHAR(300) NOT NULL DEFAULT '',
            admin_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_sms_log_store_created (store_id, created_at),
            KEY idx_sms_log_order (order_id, event, mode, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_sms_log');
        $this->addSql('DROP TABLE IF EXISTS mc_sms_settings');
    }
}
