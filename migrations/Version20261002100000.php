<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Captcha settings (built-in, reCAPTCHA, Turnstile) and consent-gated tracking tags.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_captcha_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(24) NOT NULL DEFAULT 'none',
            site_key VARCHAR(255) NOT NULL DEFAULT '',
            secret_enc TEXT NULL,
            score_threshold TINYINT UNSIGNED NOT NULL DEFAULT 50,
            forms TEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_captcha_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_tracking_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            ga4_id VARCHAR(32) NOT NULL DEFAULT '',
            gtm_id VARCHAR(32) NOT NULL DEFAULT '',
            meta_pixel_id VARCHAR(32) NOT NULL DEFAULT '',
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_tracking_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_tracking_settings');
        $this->addSql('DROP TABLE IF EXISTS mc_captcha_settings');
    }
}
