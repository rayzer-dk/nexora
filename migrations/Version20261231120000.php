<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product badges: room for a background and a text colour in the tone value.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_product_badge MODIFY tone VARCHAR(32) NOT NULL DEFAULT 'primary'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE mc_product_badge SET tone=LEFT(tone,7) WHERE tone LIKE '#%'");
        $this->addSql("ALTER TABLE mc_product_badge MODIFY tone VARCHAR(16) NOT NULL DEFAULT 'primary'");
    }
}
