<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Storefront read projections, precomputed facets and search scaling metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_storefront_product_projection (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            market_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(32) NOT NULL,
            currency CHAR(3) NOT NULL,
            status VARCHAR(32) NOT NULL,
            payload JSON NOT NULL,
            content_hash BINARY(32) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX uniq_storefront_projection (product_id,store_id,market_id,locale,currency),
            INDEX idx_storefront_projection_context (store_id,market_id,locale,currency,status,product_id),
            CONSTRAINT fk_storefront_projection_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE mc_storefront_facet_projection (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            market_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(32) NOT NULL,
            currency CHAR(3) NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            payload JSON NOT NULL,
            generated_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX uniq_storefront_facet_projection (store_id,market_id,locale,currency,category_id),
            INDEX idx_storefront_facet_projection_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_storefront_facet_projection');
        $this->addSql('DROP TABLE IF EXISTS mc_storefront_product_projection');
    }
}
