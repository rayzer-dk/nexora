<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270101030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Layout builder presence: who has a builder page open.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_layout_presence (
            store_id BIGINT UNSIGNED NOT NULL,
            layout_type VARCHAR(32) NOT NULL,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            first_seen_at DATETIME(6) NOT NULL,
            last_seen_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id, layout_type, admin_user_id),
            KEY idx_layout_presence_seen (last_seen_at),
            CONSTRAINT fk_layout_presence_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_layout_presence_admin FOREIGN KEY (admin_user_id) REFERENCES mc_admin_user(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_layout_presence');
    }
}
