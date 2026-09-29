<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configurable product badges (new/sale/bestseller/manual) and delivery countries/regions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_product_badge (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(32) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            tone VARCHAR(16) NOT NULL DEFAULT 'primary',
            labels_json JSON NOT NULL,
            window_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
            min_sold INT UNSIGNED NOT NULL DEFAULT 5,
            priority SMALLINT NOT NULL DEFAULT 100,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_product_badge_code (store_id, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_product_badge_product (
            badge_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (badge_id, product_id),
            KEY idx_badge_product_product (product_id),
            CONSTRAINT fk_badge_product_badge FOREIGN KEY (badge_id) REFERENCES mc_product_badge(id) ON DELETE CASCADE,
            CONSTRAINT fk_badge_product_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_shipping_country (
            store_id BIGINT UNSIGNED NOT NULL,
            country_code CHAR(2) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (store_id, country_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_shipping_region (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            country_code CHAR(2) NOT NULL,
            code VARCHAR(32) NOT NULL,
            name VARCHAR(190) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_shipping_region (store_id, country_code, code),
            KEY idx_shipping_region_country (store_id, country_code, enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_shipping_region');
        $this->addSql('DROP TABLE mc_shipping_country');
        $this->addSql('DROP TABLE mc_product_badge_product');
        $this->addSql('DROP TABLE mc_product_badge');
    }
}
