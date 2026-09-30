<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'First-party visit analytics (sessions, daily page counters), AI assistant settings and usage log.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql("CREATE TABLE mc_analytics_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 400,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_analytics_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_analytics_session (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            visitor_hash BINARY(16) NOT NULL,
            started_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            pageviews INT UNSIGNED NOT NULL DEFAULT 1,
            landing_path VARCHAR(190) NOT NULL,
            referrer_host VARCHAR(120) NOT NULL DEFAULT '',
            source VARCHAR(80) NOT NULL DEFAULT 'direct',
            medium VARCHAR(40) NOT NULL DEFAULT 'none',
            campaign VARCHAR(120) NOT NULL DEFAULT '',
            device VARCHAR(8) NOT NULL DEFAULT 'desktop',
            viewed_product TINYINT(1) NOT NULL DEFAULT 0,
            added_to_cart TINYINT(1) NOT NULL DEFAULT 0,
            started_checkout TINYINT(1) NOT NULL DEFAULT 0,
            ordered TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_analytics_session_visitor (store_id, visitor_hash, last_seen_at),
            KEY idx_analytics_session_started (store_id, started_at),
            CONSTRAINT fk_analytics_session_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_analytics_page_daily (
            store_id BIGINT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            path VARCHAR(190) NOT NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            entrances INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (store_id, day, path),
            CONSTRAINT fk_analytics_page_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_ai_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            daily_limit INT UNSIGNED NOT NULL DEFAULT 200,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_ai_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_ai_provider_config (
            store_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(16) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            model VARCHAR(80) NOT NULL DEFAULT '',
            api_key_enc TEXT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id, provider),
            CONSTRAINT fk_ai_provider_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_ai_usage (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            admin_subject VARCHAR(120) NOT NULL DEFAULT '',
            task VARCHAR(32) NOT NULL,
            provider VARCHAR(16) NOT NULL,
            model VARCHAR(80) NOT NULL DEFAULT '',
            input_chars INT UNSIGNED NOT NULL DEFAULT 0,
            output_chars INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_ai_usage_store_day (store_id, created_at),
            CONSTRAINT fk_ai_usage_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
    }

    public function down(Schema $schema): void
    {
        foreach (['mc_ai_usage', 'mc_ai_provider_config', 'mc_ai_settings', 'mc_analytics_page_daily', 'mc_analytics_session', 'mc_analytics_settings'] as $table) {
            $this->addSql('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
