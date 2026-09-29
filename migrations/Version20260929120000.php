<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Two-factor authentication (TOTP) with recovery codes for administrators.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_admin_mfa (
            admin_id BIGINT UNSIGNED NOT NULL,
            secret_encrypted VARBINARY(255) NOT NULL,
            enabled_at DATETIME(6) NULL,
            recovery_hashes_json JSON NOT NULL,
            last_used_step BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (admin_id),
            CONSTRAINT fk_admin_mfa_admin FOREIGN KEY (admin_id) REFERENCES mc_admin_user(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_admin_mfa');
    }
}
