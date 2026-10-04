<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261204100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-store SEO settings (optional category path in product addresses) and blog subcategories.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mc_seo_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            product_category_path TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_seo_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE mc_blog_category ADD parent_id BIGINT UNSIGNED NULL AFTER store_id, ADD KEY idx_blog_category_parent (parent_id), ADD CONSTRAINT fk_blog_category_parent FOREIGN KEY (parent_id) REFERENCES mc_blog_category(id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_blog_category DROP FOREIGN KEY fk_blog_category_parent, DROP KEY idx_blog_category_parent, DROP COLUMN parent_id');
        $this->addSql('DROP TABLE mc_seo_settings');
    }
}
