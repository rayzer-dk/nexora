<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dashboard: each administrator keeps their own order and visibility of the dashboard blocks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mc_admin_dashboard_layout (
            admin_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            layout_json JSON NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (admin_id, store_id),
            CONSTRAINT fk_dash_layout_admin FOREIGN KEY (admin_id) REFERENCES mc_admin_user(id) ON DELETE CASCADE,
            CONSTRAINT fk_dash_layout_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_admin_dashboard_layout');
    }
}
