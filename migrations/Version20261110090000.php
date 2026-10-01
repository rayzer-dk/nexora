<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Photos and videos of a product share one ordered list.';
    }

    public function up(Schema $schema): void
    {
        // A video used to be placed "at the start" or "at the end" of the photos; now it has its own place in the shared order.
        $this->addSql("UPDATE mc_product_video SET sort_order=IF(placement='start',0,100000+sort_order)");
        $this->addSql('ALTER TABLE mc_product_video DROP INDEX idx_product_video_order');
        $this->addSql('ALTER TABLE mc_product_video DROP COLUMN placement');
        $this->addSql('ALTER TABLE mc_product_video ADD KEY idx_product_video_order (product_id, sort_order)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_video DROP INDEX idx_product_video_order');
        $this->addSql("ALTER TABLE mc_product_video ADD COLUMN placement VARCHAR(8) NOT NULL DEFAULT 'end' AFTER title");
        $this->addSql('ALTER TABLE mc_product_video ADD KEY idx_product_video_order (product_id, placement, sort_order)');
    }
}
