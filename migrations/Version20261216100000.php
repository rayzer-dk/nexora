<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261216100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer options of a product (single and multiple choice, text, date, time) with price and weight actions; cart lines keep their choices apart.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $tail = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_product_addon (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            public_id BINARY(16) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(32) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            required TINYINT(1) NOT NULL DEFAULT 0,
            price_mode VARCHAR(4) NOT NULL DEFAULT \'add\',
            price_delta_minor BIGINT NOT NULL DEFAULT 0,
            weight_delta_g INT NOT NULL DEFAULT 0,
            max_length INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_product_addon_public (public_id),
            KEY idx_product_addon_product (product_id, sort_order),
            CONSTRAINT fk_product_addon_product FOREIGN KEY (product_id) REFERENCES mc_product (id) ON DELETE CASCADE
        ' . $tail);
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_product_addon_translation (
            addon_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            name VARCHAR(190) NOT NULL,
            PRIMARY KEY (addon_id, locale),
            CONSTRAINT fk_product_addon_tr FOREIGN KEY (addon_id) REFERENCES mc_product_addon (id) ON DELETE CASCADE
        ' . $tail);
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_product_addon_value (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            addon_id BIGINT UNSIGNED NOT NULL,
            price_mode VARCHAR(4) NOT NULL DEFAULT \'add\',
            price_delta_minor BIGINT NOT NULL DEFAULT 0,
            weight_delta_g INT NOT NULL DEFAULT 0,
            media_asset_id BIGINT UNSIGNED NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_product_addon_value_addon (addon_id, sort_order),
            CONSTRAINT fk_product_addon_value FOREIGN KEY (addon_id) REFERENCES mc_product_addon (id) ON DELETE CASCADE
        ' . $tail);
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_product_addon_value_translation (
            value_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            name VARCHAR(190) NOT NULL,
            PRIMARY KEY (value_id, locale),
            CONSTRAINT fk_product_addon_value_tr FOREIGN KEY (value_id) REFERENCES mc_product_addon_value (id) ON DELETE CASCADE
        ' . $tail);
        $this->addSql("ALTER TABLE mc_cart_item ADD addon_hash CHAR(40) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE mc_cart_item DROP INDEX uq_cart_item_variant, ADD UNIQUE KEY uq_cart_item_variant (cart_id, variant_id, addon_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE ci1 FROM mc_cart_item ci1 JOIN mc_cart_item ci2 ON ci2.cart_id=ci1.cart_id AND ci2.variant_id=ci1.variant_id AND ci2.id<ci1.id');
        $this->addSql('ALTER TABLE mc_cart_item DROP INDEX uq_cart_item_variant, ADD UNIQUE KEY uq_cart_item_variant (cart_id, variant_id), DROP COLUMN addon_hash');
        foreach (['mc_product_addon_value_translation', 'mc_product_addon_value', 'mc_product_addon_translation', 'mc_product_addon'] as $table) {
            $this->addSql("DROP TABLE IF EXISTS $table");
        }
    }
}
