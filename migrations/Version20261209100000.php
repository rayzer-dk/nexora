<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261209100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark orders whose customer details were edited by the shop team.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sales_order ADD COLUMN IF NOT EXISTS customer_edited_at DATETIME(6) NULL');
        $this->addSql('ALTER TABLE mc_sales_order ADD COLUMN IF NOT EXISTS customer_edited_by VARCHAR(190) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sales_order DROP COLUMN IF EXISTS customer_edited_by');
        $this->addSql('ALTER TABLE mc_sales_order DROP COLUMN IF EXISTS customer_edited_at');
    }
}
