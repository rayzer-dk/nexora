<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Per-store choice of the exchange-rate source for converted currencies: official NBU feed or a manual rate. */
final class Version20260927200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exchange-rate source per store currency.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_store_currency ADD rate_source VARCHAR(16) NOT NULL DEFAULT 'nbu' AFTER auto_convert");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_store_currency DROP COLUMN rate_source');
    }
}
