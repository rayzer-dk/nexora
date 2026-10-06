<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261222100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog: an optional H1 heading of products and categories, separate from the name.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_translation ADD h1 VARCHAR(255) NULL AFTER name');
        $this->addSql('ALTER TABLE mc_category_translation ADD h1 VARCHAR(255) NULL AFTER name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_category_translation DROP COLUMN h1');
        $this->addSql('ALTER TABLE mc_product_translation DROP COLUMN h1');
    }
}
