<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exchange-rate providers: per-currency source (auto/ecb/nbu/nbp/cnb/manual), markup, provider status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_store_currency ADD COLUMN rate_markup_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER rate_source");
        $this->addSql("ALTER TABLE mc_store_currency MODIFY rate_source VARCHAR(16) NOT NULL DEFAULT 'auto'");
        // Until now 'nbu' meant "official rates": ECB first, NBU for what the ECB does not publish. That is the new 'auto'.
        $this->addSql("UPDATE mc_store_currency SET rate_source = 'auto' WHERE rate_source = 'nbu'");

        $this->addSql("CREATE TABLE mc_exchange_rate_provider_status (
            provider VARCHAR(16) NOT NULL,
            last_attempt_at DATETIME(6) NOT NULL,
            last_success_at DATETIME(6) NULL,
            last_rate_date DATE NULL,
            last_error VARCHAR(500) NULL,
            pairs_stored INT UNSIGNED NOT NULL DEFAULT 0,
            consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (provider)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_exchange_rate_provider_status');
        $this->addSql("UPDATE mc_store_currency SET rate_source = 'nbu' WHERE rate_source IN ('auto','ecb','nbp','cnb')");
        $this->addSql("ALTER TABLE mc_store_currency MODIFY rate_source VARCHAR(16) NOT NULL DEFAULT 'nbu'");
        $this->addSql('ALTER TABLE mc_store_currency DROP COLUMN rate_markup_bps');
    }
}
