<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Blog: categories, per-article metadata (cover, author, reading time, SEO overrides) and tags.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_blog_category (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(120) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_blog_category_slug (store_id, slug),
            CONSTRAINT fk_blog_category_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_blog_category_translation (
            category_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            meta_title VARCHAR(255) NULL,
            meta_description VARCHAR(500) NULL,
            PRIMARY KEY (category_id, locale),
            CONSTRAINT fk_blog_category_tr_category FOREIGN KEY (category_id) REFERENCES mc_blog_category(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_blog_article_meta (
            content_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            cover_url VARCHAR(500) NULL,
            cover_alt VARCHAR(255) NULL,
            author_name VARCHAR(190) NULL,
            featured TINYINT(1) NOT NULL DEFAULT 0,
            noindex TINYINT(1) NOT NULL DEFAULT 0,
            canonical_url VARCHAR(500) NULL,
            reading_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (content_id),
            KEY idx_blog_meta_category (category_id),
            KEY idx_blog_meta_featured (featured),
            CONSTRAINT fk_blog_meta_content FOREIGN KEY (content_id) REFERENCES mc_content_entry(id) ON DELETE CASCADE,
            CONSTRAINT fk_blog_meta_category FOREIGN KEY (category_id) REFERENCES mc_blog_category(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_blog_article_tag (
            content_id BIGINT UNSIGNED NOT NULL,
            tag_slug VARCHAR(120) NOT NULL,
            tag_name VARCHAR(120) NOT NULL,
            PRIMARY KEY (content_id, tag_slug),
            KEY idx_blog_tag_slug (tag_slug),
            CONSTRAINT fk_blog_tag_content FOREIGN KEY (content_id) REFERENCES mc_content_entry(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_blog_article_tag');
        $this->addSql('DROP TABLE mc_blog_article_meta');
        $this->addSql('DROP TABLE mc_blog_category_translation');
        $this->addSql('DROP TABLE mc_blog_category');
    }
}
