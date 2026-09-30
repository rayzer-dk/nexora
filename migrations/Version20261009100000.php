<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin password recovery tokens and the category cover image.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_admin_password_reset (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT UNSIGNED NOT NULL,
            token_hash BINARY(32) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            consumed_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_admin_reset_token (token_hash),
            KEY idx_admin_reset_admin (admin_id, consumed_at, created_at),
            CONSTRAINT fk_admin_reset_admin FOREIGN KEY (admin_id) REFERENCES mc_admin_user(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_category_image (
            category_id BIGINT UNSIGNED NOT NULL,
            asset_id BIGINT UNSIGNED NOT NULL,
            alt_text VARCHAR(255) NOT NULL DEFAULT '',
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (category_id),
            KEY idx_category_image_asset (asset_id),
            CONSTRAINT fk_category_image_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_category_image');
        $this->addSql('DROP TABLE IF EXISTS mc_admin_password_reset');
    }
}
