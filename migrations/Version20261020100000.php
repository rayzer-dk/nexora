<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Information pages CRUD metadata, blog featured image size/alignment, public store contacts (map, social links).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_content_page_meta (
            content_id BIGINT UNSIGNED NOT NULL,
            page_group VARCHAR(16) NOT NULL DEFAULT 'company',
            layout VARCHAR(16) NOT NULL DEFAULT 'default',
            show_in_footer TINYINT(1) NOT NULL DEFAULT 1,
            show_in_menu TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            noindex TINYINT(1) NOT NULL DEFAULT 0,
            canonical_url VARCHAR(500) NULL,
            og_asset_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (content_id),
            KEY idx_content_page_meta_menu (show_in_footer, show_in_menu, page_group, sort_order),
            CONSTRAINT fk_content_page_meta_content FOREIGN KEY (content_id) REFERENCES mc_content_entry(id) ON DELETE CASCADE,
            CONSTRAINT fk_content_page_meta_og FOREIGN KEY (og_asset_id) REFERENCES mc_media_asset(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // The built-in pages keep their footer group and order from the information page catalogue.
        $this->addSql("INSERT IGNORE INTO mc_content_page_meta (content_id, page_group, layout, show_in_footer, show_in_menu, sort_order, noindex)
            SELECT ce.id,
                   CASE ce.system_key
                       WHEN 'about' THEN 'company' WHEN 'contacts' THEN 'company'
                       WHEN 'delivery' THEN 'buyers' WHEN 'payment' THEN 'buyers' WHEN 'returns' THEN 'buyers' WHEN 'warranty' THEN 'buyers' WHEN 'faq' THEN 'buyers'
                       WHEN 'privacy' THEN 'legal' WHEN 'cookies' THEN 'legal' WHEN 'terms' THEN 'legal'
                       ELSE 'other' END,
                   'default', 1, 0,
                   CASE ce.system_key WHEN 'about' THEN 10 WHEN 'contacts' THEN 20 WHEN 'delivery' THEN 10 WHEN 'payment' THEN 20 WHEN 'returns' THEN 30 WHEN 'warranty' THEN 40 WHEN 'faq' THEN 50 WHEN 'privacy' THEN 10 WHEN 'cookies' THEN 20 WHEN 'terms' THEN 30 ELSE 100 END,
                   0
            FROM mc_content_entry ce WHERE ce.content_type = 'page'");

        $this->addSql("ALTER TABLE mc_blog_article_meta
            ADD COLUMN image_size VARCHAR(1) NOT NULL DEFAULT 'm' AFTER cover_alt,
            ADD COLUMN image_align VARCHAR(8) NOT NULL DEFAULT 'none' AFTER image_size");

        $this->addSql("CREATE TABLE mc_store_contact (
            store_id BIGINT UNSIGNED NOT NULL,
            extra_phones VARCHAR(500) NULL,
            map_mode VARCHAR(8) NOT NULL DEFAULT 'none',
            map_lat DECIMAL(9,6) NULL,
            map_lng DECIMAL(9,6) NULL,
            map_zoom TINYINT UNSIGNED NOT NULL DEFAULT 16,
            facebook_url VARCHAR(500) NULL,
            instagram_url VARCHAR(500) NULL,
            telegram_url VARCHAR(500) NULL,
            tiktok_url VARCHAR(500) NULL,
            youtube_url VARCHAR(500) NULL,
            x_url VARCHAR(500) NULL,
            linkedin_url VARCHAR(500) NULL,
            viber_url VARCHAR(500) NULL,
            whatsapp_url VARCHAR(500) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_store_contact_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE mc_store_contact_translation (
            store_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            address VARCHAR(1000) NULL,
            working_hours VARCHAR(1000) NULL,
            note VARCHAR(1000) NULL,
            PRIMARY KEY (store_id, locale),
            CONSTRAINT fk_store_contact_tr_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_store_contact_tr_locale FOREIGN KEY (locale) REFERENCES mc_locale(code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_store_contact_translation');
        $this->addSql('DROP TABLE mc_store_contact');
        $this->addSql('ALTER TABLE mc_blog_article_meta DROP COLUMN image_align, DROP COLUMN image_size');
        $this->addSql('DROP TABLE mc_content_page_meta');
    }
}
