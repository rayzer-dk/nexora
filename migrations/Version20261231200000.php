<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'VAT number of a business buyer checked in VIES at checkout: the answer is kept with the order.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sales_order ADD company_vat_status VARCHAR(16) NULL, ADD company_vat_name VARCHAR(190) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_sales_order DROP COLUMN company_vat_status, DROP COLUMN company_vat_name');
    }
}
