<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261207100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An icon (Lucide name picked in the admin) for menu items, product badges and categories.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_navigation_item ADD icon VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE mc_product_badge ADD icon VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE mc_category ADD icon VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_navigation_item DROP icon');
        $this->addSql('ALTER TABLE mc_product_badge DROP icon');
        $this->addSql('ALTER TABLE mc_category DROP icon');
    }
}
