<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UX operations: saved admin views, category merchandising, search boosts and centralized scheduler state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_admin_saved_view (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            admin_id BIGINT UNSIGNED NULL,
            entity_type VARCHAR(64) NOT NULL,
            name VARCHAR(190) NOT NULL,
            filters_json JSON NOT NULL,
            columns_json JSON NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_admin_saved_view_lookup (store_id, entity_type, admin_id, id),
            CONSTRAINT fk_admin_saved_view_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_admin_saved_view_admin FOREIGN KEY (admin_id) REFERENCES mc_admin_user(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_category_merchandising (
            category_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            mode VARCHAR(24) NOT NULL DEFAULT 'manual',
            pinned_json JSON NOT NULL,
            rules_json JSON NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (category_id, store_id),
            KEY idx_category_merch_store (store_id, updated_at),
            CONSTRAINT fk_category_merch_category FOREIGN KEY (category_id) REFERENCES mc_category(id) ON DELETE CASCADE,
            CONSTRAINT fk_category_merch_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_category_merch_admin FOREIGN KEY (updated_by) REFERENCES mc_admin_user(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_search_boost (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            query_text VARCHAR(190) NOT NULL,
            query_hash BINARY(32) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            weight SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_search_boost (store_id, locale, query_hash, product_id),
            KEY idx_search_boost_query (store_id, locale, status, query_hash, weight),
            CONSTRAINT fk_search_boost_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_search_boost_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_scheduled_task_state (
            task_code VARCHAR(96) NOT NULL,
            last_started_at DATETIME(6) NULL,
            last_finished_at DATETIME(6) NULL,
            last_status VARCHAR(24) NULL,
            last_message VARCHAR(1000) NULL,
            last_duration_ms INT UNSIGNED NULL,
            next_due_at DATETIME(6) NULL,
            run_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            fail_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (task_code),
            KEY idx_scheduled_task_due (next_due_at, task_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_scheduled_task_state');
        $this->addSql('DROP TABLE IF EXISTS mc_search_boost');
        $this->addSql('DROP TABLE IF EXISTS mc_category_merchandising');
        $this->addSql('DROP TABLE IF EXISTS mc_admin_saved_view');
    }
}
