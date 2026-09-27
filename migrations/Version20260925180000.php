<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add per-store recommendation engine settings.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_recommendation_setting (
            store_id BIGINT UNSIGNED NOT NULL,
            related_mode VARCHAR(16) NOT NULL DEFAULT 'hybrid',
            complementary_mode VARCHAR(16) NOT NULL DEFAULT 'hybrid',
            auto_limit TINYINT UNSIGNED NOT NULL DEFAULT 8,
            in_stock_only TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY(store_id),
            CONSTRAINT fk_recommendation_setting_store FOREIGN KEY(store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("INSERT IGNORE INTO mc_recommendation_setting(store_id,related_mode,complementary_mode,auto_limit,in_stock_only,updated_at) SELECT id,'hybrid','hybrid',8,0,UTC_TIMESTAMP(6) FROM mc_store");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_recommendation_setting');
    }
}
