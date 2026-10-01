<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generic system key/value store (cron settings and last-run record, cached provider model lists).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS mc_system_setting (
            setting_key VARCHAR(120) NOT NULL,
            setting_value MEDIUMTEXT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_system_setting');
    }
}
