<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product option values get a price difference, a colour swatch and a product picture.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_option_value ADD COLUMN price_delta_minor BIGINT NOT NULL DEFAULT 0 AFTER code, ADD COLUMN swatch VARCHAR(16) NULL AFTER price_delta_minor, ADD COLUMN media_asset_id BIGINT UNSIGNED NULL AFTER swatch');
        $this->addSql('ALTER TABLE mc_product_option_value ADD CONSTRAINT fk_product_option_value_media FOREIGN KEY (media_asset_id) REFERENCES mc_media_asset(id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_option_value DROP FOREIGN KEY fk_product_option_value_media');
        $this->addSql('ALTER TABLE mc_product_option_value DROP COLUMN media_asset_id, DROP COLUMN swatch, DROP COLUMN price_delta_minor');
    }
}
