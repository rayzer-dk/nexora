<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Floating contact widget, editable notification templates and IndexNow state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_contact_widget (
            store_id BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            position VARCHAR(8) NOT NULL DEFAULT 'right',
            callback_enabled TINYINT(1) NOT NULL DEFAULT 1,
            write_enabled TINYINT(1) NOT NULL DEFAULT 1,
            phone VARCHAR(32) NULL,
            email VARCHAR(190) NULL,
            viber VARCHAR(64) NULL,
            messenger VARCHAR(190) NULL,
            telegram VARCHAR(64) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_contact_widget_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_notification_template (
            store_id BIGINT UNSIGNED NOT NULL,
            template_code VARCHAR(64) NOT NULL,
            locale VARCHAR(16) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id, template_code, locale),
            CONSTRAINT fk_notification_template_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_indexnow_state (
            store_id BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            last_run_at DATETIME(6) NULL,
            last_status VARCHAR(190) NULL,
            last_count INT NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_indexnow_state_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_notification_template');
        $this->addSql('DROP TABLE IF EXISTS mc_indexnow_state');
        $this->addSql('DROP TABLE IF EXISTS mc_contact_widget');
    }
}
