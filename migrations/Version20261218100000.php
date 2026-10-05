<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261218100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shopper choices of a product with a limited stock.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_addon_value ADD stock_quantity INT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_addon_value DROP COLUMN stock_quantity');
    }
}
