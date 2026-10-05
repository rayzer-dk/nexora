<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261210100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scheduled campaigns (send_at) and a language filter for campaign recipients.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_marketing_campaign ADD COLUMN IF NOT EXISTS send_at DATETIME(6) NULL');
        $this->addSql('ALTER TABLE mc_marketing_campaign ADD COLUMN IF NOT EXISTS locale VARCHAR(16) NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_marketing_campaign DROP COLUMN IF EXISTS locale');
        $this->addSql('ALTER TABLE mc_marketing_campaign DROP COLUMN IF EXISTS send_at');
    }
}
