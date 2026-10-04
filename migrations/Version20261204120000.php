<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261204120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Category description: one text, shown above or below the products for the whole store.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_seo_settings ADD category_description_position VARCHAR(8) NOT NULL DEFAULT 'top' AFTER product_category_path");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_seo_settings DROP COLUMN category_description_position');
    }
}
