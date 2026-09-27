<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recovery snapshots, update attempts and optional component circuit-breaker state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_recovery_snapshot (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            snapshot_key VARCHAR(96) NOT NULL,
            reason VARCHAR(190) NOT NULL,
            platform_version VARCHAR(32) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'creating',
            archive_path VARCHAR(1000) NULL,
            archive_sha256 CHAR(64) NULL,
            size_bytes BIGINT UNSIGNED NULL,
            actor_subject VARCHAR(190) NULL,
            created_at DATETIME(6) NOT NULL,
            completed_at DATETIME(6) NULL,
            verified_at DATETIME(6) NULL,
            restored_at DATETIME(6) NULL,
            last_error VARCHAR(1000) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recovery_snapshot_public_id (public_id),
            UNIQUE KEY uq_recovery_snapshot_key (snapshot_key),
            KEY idx_recovery_snapshot_status_created (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_update_attempt (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            target_version VARCHAR(32) NOT NULL,
            channel VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            package_sha256 CHAR(64) NOT NULL,
            rollback_snapshot_id BIGINT UNSIGNED NULL,
            staged_path VARCHAR(1000) NULL,
            error_summary VARCHAR(1000) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            completed_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_update_attempt_public_id (public_id),
            KEY idx_update_attempt_status_created (status, created_at),
            KEY idx_update_attempt_snapshot (rollback_snapshot_id),
            CONSTRAINT fk_update_attempt_snapshot FOREIGN KEY (rollback_snapshot_id) REFERENCES mc_recovery_snapshot(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_component_health (
            component_type VARCHAR(32) NOT NULL,
            component_code VARCHAR(190) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'healthy',
            consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            failure_window_started_at DATETIME(6) NULL,
            last_failure_at DATETIME(6) NULL,
            quarantined_until DATETIME(6) NULL,
            last_error_hash BINARY(32) NULL,
            last_error_summary VARCHAR(1000) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (component_type, component_code),
            KEY idx_component_health_status (status, quarantined_until, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_update_attempt');
        $this->addSql('DROP TABLE IF EXISTS mc_recovery_snapshot');
        $this->addSql('DROP TABLE IF EXISTS mc_component_health');
    }
}
