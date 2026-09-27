<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fail-safe configuration revisions, extension quarantine state and runtime incident journal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_configuration_revision (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            namespace VARCHAR(64) NOT NULL,
            config_key VARCHAR(64) NOT NULL,
            revision_number INT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            payload JSON NOT NULL,
            checksum_sha256 CHAR(64) NOT NULL,
            actor_subject VARCHAR(190) NULL,
            parent_revision_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            activated_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_configuration_revision_public_id (public_id),
            UNIQUE KEY uq_configuration_revision_number (store_id, namespace, config_key, revision_number),
            KEY idx_configuration_revision_active (store_id, namespace, config_key, status, revision_number),
            KEY idx_configuration_revision_parent (parent_revision_id),
            CONSTRAINT fk_configuration_revision_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_configuration_revision_parent FOREIGN KEY (parent_revision_id) REFERENCES mc_configuration_revision(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_extension_installation (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            code VARCHAR(96) NOT NULL,
            name VARCHAR(190) NOT NULL,
            version VARCHAR(32) NOT NULL,
            extension_type VARCHAR(32) NOT NULL,
            api_version VARCHAR(32) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'staged',
            package_sha256 CHAR(64) NOT NULL,
            install_path VARCHAR(768) NOT NULL,
            manifest_json JSON NOT NULL,
            failure_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(1000) NULL,
            installed_at DATETIME(6) NOT NULL,
            activated_at DATETIME(6) NULL,
            disabled_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_extension_installation_public_id (public_id),
            UNIQUE KEY uq_extension_installation_code_version (code, version),
            KEY idx_extension_installation_status (status, code),
            KEY idx_extension_installation_code (code, installed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_runtime_incident (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            request_id VARCHAR(64) NOT NULL,
            area VARCHAR(32) NOT NULL,
            route_name VARCHAR(190) NULL,
            path_hash BINARY(32) NOT NULL,
            severity VARCHAR(24) NOT NULL,
            fallback_mode VARCHAR(32) NULL,
            error_class VARCHAR(255) NOT NULL,
            error_summary VARCHAR(1000) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_runtime_incident_public_id (public_id),
            KEY idx_runtime_incident_created (created_at),
            KEY idx_runtime_incident_area_created (area, created_at),
            KEY idx_runtime_incident_route_created (route_name, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_runtime_incident');
        $this->addSql('DROP TABLE IF EXISTS mc_extension_installation');
        $this->addSql('DROP TABLE IF EXISTS mc_configuration_revision');
    }
}
