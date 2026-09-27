<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feed Center category mapping for marketplace-specific taxonomy identifiers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_seo_route_sitemap ON mc_seo_route (store_id,locale,indexable,id)');
        $this->addSql("CREATE TABLE mc_feed_category_mapping (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(32) NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            external_category_id VARCHAR(190) NOT NULL,
            external_category_name VARCHAR(500) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX uniq_feed_category_mapping (store_id,platform,category_id),
            INDEX idx_feed_category_external (platform,external_category_id),
            CONSTRAINT fk_feed_category_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_feed_category_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_feed_category_mapping');
        $this->addSql('DROP INDEX idx_seo_route_sitemap ON mc_seo_route');
    }
}
