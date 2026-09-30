<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dashboard goals and annotations, automation rules, web push, custom fields, downloads, category bottom text, captcha outage mode.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql("CREATE TABLE mc_dashboard_goal (
            store_id BIGINT UNSIGNED NOT NULL,
            metric VARCHAR(16) NOT NULL,
            target BIGINT UNSIGNED NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id, metric),
            CONSTRAINT fk_dashboard_goal_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_dashboard_annotation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            note VARCHAR(190) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_dashboard_annotation_day (store_id, day),
            CONSTRAINT fk_dashboard_annotation_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_automation_rule (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            event_name VARCHAR(64) NOT NULL,
            min_total_minor BIGINT UNSIGNED NULL,
            action_type VARCHAR(24) NOT NULL,
            action_target VARCHAR(500) NOT NULL DEFAULT '',
            action_text VARCHAR(500) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            run_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_run_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_automation_rule_event (store_id, event_name, enabled),
            CONSTRAINT fk_automation_rule_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_automation_run (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            event_ref VARCHAR(190) NOT NULL,
            status VARCHAR(12) NOT NULL,
            message VARCHAR(300) NOT NULL DEFAULT '',
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_automation_run_once (rule_id, event_ref),
            KEY idx_automation_run_created (created_at),
            CONSTRAINT fk_automation_run_rule FOREIGN KEY (rule_id) REFERENCES mc_automation_rule(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_push_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            vapid_public VARCHAR(128) NOT NULL DEFAULT '',
            vapid_private_enc TEXT NULL,
            subject VARCHAR(190) NOT NULL DEFAULT '',
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_push_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_push_subscription (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            audience VARCHAR(12) NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            endpoint VARCHAR(1024) NOT NULL,
            p256dh VARCHAR(128) NOT NULL,
            auth VARCHAR(64) NOT NULL,
            locale VARCHAR(16) NOT NULL DEFAULT '',
            failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            last_success_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_push_endpoint (store_id, endpoint_hash),
            KEY idx_push_audience (store_id, audience),
            CONSTRAINT fk_push_subscription_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_custom_field_definition (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            owner_type VARCHAR(12) NOT NULL,
            code VARCHAR(48) NOT NULL,
            label VARCHAR(160) NOT NULL,
            field_type VARCHAR(12) NOT NULL DEFAULT 'text',
            show_on_storefront TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_custom_field_code (store_id, owner_type, code),
            CONSTRAINT fk_custom_field_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_custom_field_value (
            definition_id BIGINT UNSIGNED NOT NULL,
            owner_id BIGINT UNSIGNED NOT NULL,
            value TEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (definition_id, owner_id),
            KEY idx_custom_field_owner (owner_id),
            CONSTRAINT fk_custom_field_value_def FOREIGN KEY (definition_id) REFERENCES mc_custom_field_definition(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_download_file (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            file_url VARCHAR(500) NOT NULL,
            file_ext VARCHAR(8) NOT NULL DEFAULT '',
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            group_label VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_download_store (store_id, status, sort_order),
            CONSTRAINT fk_download_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql('ALTER TABLE mc_category_translation ADD COLUMN description_bottom MEDIUMTEXT NULL');
        $this->addSql("ALTER TABLE mc_captcha_settings ADD COLUMN fail_mode VARCHAR(8) NOT NULL DEFAULT 'open'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_captcha_settings DROP COLUMN fail_mode');
        $this->addSql('ALTER TABLE mc_category_translation DROP COLUMN description_bottom');
        foreach (['mc_download_file','mc_custom_field_value','mc_custom_field_definition','mc_push_subscription','mc_push_settings','mc_automation_run','mc_automation_rule','mc_dashboard_annotation','mc_dashboard_goal'] as $table) {
            $this->addSql('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
