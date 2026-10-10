<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'File libraries: folders and files for product documents and digital downloads.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS mc_file_folder (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            library VARCHAR(16) NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_file_folder_parent (store_id, library, parent_id, name),
            CONSTRAINT fk_file_folder_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_folder_parent FOREIGN KEY (parent_id) REFERENCES mc_file_folder(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE IF NOT EXISTS mc_file_item (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            library VARCHAR(16) NOT NULL,
            folder_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            storage_key VARCHAR(500) NOT NULL,
            mime_type VARCHAR(190) NOT NULL,
            bytes BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_file_item_public (public_id),
            KEY idx_file_item_folder (store_id, library, folder_id, id),
            CONSTRAINT fk_file_item_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_item_folder FOREIGN KEY (folder_id) REFERENCES mc_file_folder(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_file_item');
        $this->addSql('DROP TABLE IF EXISTS mc_file_folder');
    }
}
