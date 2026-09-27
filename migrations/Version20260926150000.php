<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extension lifecycle audit trail and reusable import mapping profiles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_extension_lifecycle_event (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            installation_id BIGINT UNSIGNED NULL,
            code VARCHAR(96) NOT NULL,
            version VARCHAR(32) NOT NULL,
            action VARCHAR(32) NOT NULL,
            from_status VARCHAR(24) NULL,
            to_status VARCHAR(24) NULL,
            result VARCHAR(24) NOT NULL DEFAULT 'success',
            message VARCHAR(1000) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_extension_lifecycle_code_created (code, created_at),
            KEY idx_extension_lifecycle_installation (installation_id, created_at),
            KEY idx_extension_lifecycle_result_created (result, created_at),
            CONSTRAINT fk_extension_lifecycle_installation FOREIGN KEY (installation_id) REFERENCES mc_extension_installation(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_import_profile (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            source_format VARCHAR(24) NOT NULL,
            mapping_json JSON NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_import_profile_store_name (store_id, name),
            KEY idx_import_profile_store_updated (store_id, updated_at),
            CONSTRAINT fk_import_profile_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_import_profile');
        $this->addSql('DROP TABLE IF EXISTS mc_extension_lifecycle_event');
    }
}
