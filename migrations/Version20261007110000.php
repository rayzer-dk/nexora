<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quality monitor: score history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_quality_snapshot (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            score TINYINT UNSIGNED NOT NULL,
            level VARCHAR(8) NOT NULL,
            ok_count SMALLINT UNSIGNED NOT NULL,
            warn_count SMALLINT UNSIGNED NOT NULL,
            fail_count SMALLINT UNSIGNED NOT NULL,
            failing JSON NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'view',
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_quality_snapshot_store (store_id, created_at),
            CONSTRAINT fk_quality_snapshot_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_quality_snapshot');
    }
}
