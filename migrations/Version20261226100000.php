<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261226100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product extras: hidden from catalog, reviews switch, reward points percent.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_extra ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER cost_minor, ADD COLUMN reviews_off TINYINT(1) NOT NULL DEFAULT 0 AFTER hidden, ADD COLUMN points_percent SMALLINT UNSIGNED NULL AFTER reviews_off');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_extra DROP COLUMN hidden, DROP COLUMN reviews_off, DROP COLUMN points_percent');
    }
}
