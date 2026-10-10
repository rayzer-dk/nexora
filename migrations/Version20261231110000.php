<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shipments: mark a tracking number that was edited by hand.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_shipment ADD COLUMN tracking_edited_at DATETIME(6) NULL, ADD COLUMN tracking_edited_by VARCHAR(190) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_shipment DROP COLUMN tracking_edited_by, DROP COLUMN tracking_edited_at');
    }
}
