<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add privacy-minimal commerce analytics search log and dedicated analytics permission.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_search_query_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            query_text VARCHAR(120) NOT NULL,
            query_hash BINARY(32) NOT NULL,
            result_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_search_query_store_created (store_id,created_at),
            KEY idx_search_query_store_zero (store_id,result_count,created_at),
            KEY idx_search_query_store_hash (store_id,query_hash,created_at),
            CONSTRAINT fk_search_query_log_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("INSERT IGNORE INTO mc_admin_permission(code,description,risk_level) VALUES ('analytics.view','View commerce analytics','low')");
        $this->addSql("INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES ('ROLE_VIEWER','analytics.view'),('ROLE_MANAGER','analytics.view'),('ROLE_SUPPORT','analytics.view')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM mc_admin_role_permission WHERE permission_code='analytics.view'");
        $this->addSql("DELETE FROM mc_admin_permission WHERE code='analytics.view'");
        $this->addSql('DROP TABLE IF EXISTS mc_search_query_log');
    }
}
