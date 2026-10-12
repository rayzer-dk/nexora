<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store director: three proposed actions a day with an apply button (own switch), and what each apply changed so it can be undone.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql('CREATE TABLE mc_director_settings (
            store_id BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            discount_percent TINYINT UNSIGNED NOT NULL DEFAULT 10,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_director_settings_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )' . $t);
        $this->addSql('CREATE TABLE mc_director_action (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            kind VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'proposed\',
            params JSON NOT NULL,
            undo_data JSON NULL,
            created_at DATETIME(6) NOT NULL,
            resolved_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_director_day (store_id, day, kind),
            CONSTRAINT fk_director_action_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )' . $t);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_director_action');
        $this->addSql('DROP TABLE mc_director_settings');
    }
}
