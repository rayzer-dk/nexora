<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extension API 1.1 migration ledger for signed trusted modules.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_extension_migration (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            installation_id BIGINT UNSIGNED NOT NULL,
            migration_key VARCHAR(190) NOT NULL,
            checksum_sha256 CHAR(64) NOT NULL,
            applied_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_extension_migration_key (installation_id,migration_key),
            KEY idx_extension_migration_applied (applied_at),
            CONSTRAINT fk_extension_migration_installation FOREIGN KEY (installation_id) REFERENCES mc_extension_installation(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_extension_migration');
    }
}
