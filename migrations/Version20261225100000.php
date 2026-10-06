<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261225100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product cost price (for margin) next to the other product extras.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_extra ADD COLUMN cost_minor BIGINT UNSIGNED NULL AFTER tags');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_product_extra DROP COLUMN cost_minor');
    }
}
