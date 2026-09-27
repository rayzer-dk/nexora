<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Platform 3.0 builders, migration resume journal, integration queues and media library metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_layout_revision (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            layout_type VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            payload JSON NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            published_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            KEY idx_layout_revision_store_type (store_id, layout_type, id),
            KEY idx_layout_revision_status (store_id, layout_type, status, id),
            CONSTRAINT fk_layout_revision_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_migration_run (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            source_code VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            entity_type VARCHAR(64) NULL,
            cursor_value VARCHAR(255) NULL,
            processed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            issue_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(1000) NULL,
            options_json JSON NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            completed_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migration_run_public_id (public_id),
            KEY idx_migration_run_status (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_integration_sync_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            integration_code VARCHAR(64) NOT NULL,
            aggregate_type VARCHAR(64) NOT NULL,
            aggregate_id VARCHAR(190) NOT NULL,
            operation VARCHAR(32) NOT NULL,
            payload JSON NOT NULL,
            dedupe_key VARCHAR(190) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME(6) NULL,
            last_error VARCHAR(1000) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            completed_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_integration_sync_dedupe (dedupe_key),
            KEY idx_integration_sync_worker (integration_code, status, available_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_media_folder (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_media_folder_parent_slug (store_id, parent_id, slug),
            KEY idx_media_folder_parent (parent_id, name),
            CONSTRAINT fk_media_folder_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_media_folder_parent FOREIGN KEY (parent_id) REFERENCES mc_media_folder(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_media_asset_meta (
            asset_id BIGINT UNSIGNED NOT NULL,
            folder_id BIGINT UNSIGNED NULL,
            alt_text VARCHAR(500) NULL,
            title VARCHAR(500) NULL,
            focal_x DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            focal_y DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            tags_json JSON NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (asset_id),
            KEY idx_media_asset_folder (folder_id, asset_id),
            CONSTRAINT fk_media_asset_meta_asset FOREIGN KEY (asset_id) REFERENCES mc_media_asset(id) ON DELETE CASCADE,
            CONSTRAINT fk_media_asset_meta_folder FOREIGN KEY (folder_id) REFERENCES mc_media_folder(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_media_asset_meta');
        $this->addSql('DROP TABLE IF EXISTS mc_media_folder');
        $this->addSql('DROP TABLE IF EXISTS mc_integration_sync_queue');
        $this->addSql('DROP TABLE IF EXISTS mc_migration_run');
        $this->addSql('DROP TABLE IF EXISTS mc_layout_revision');
    }
}
