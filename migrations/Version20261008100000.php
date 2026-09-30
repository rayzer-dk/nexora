<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Content policy (translations required before publishing) and the admin undo journal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_store_content_policy (
            store_id BIGINT UNSIGNED NOT NULL,
            require_translations VARCHAR(8) NOT NULL DEFAULT 'off',
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_content_policy_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_admin_undo (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            admin_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(32) NOT NULL,
            label VARCHAR(190) NOT NULL,
            payload JSON NOT NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            KEY idx_admin_undo_admin (admin_id, used_at, created_at),
            KEY idx_admin_undo_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_admin_undo');
        $this->addSql('DROP TABLE IF EXISTS mc_store_content_policy');
    }
}
