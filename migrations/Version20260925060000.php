<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Production multi-store domain routing and canonical storefront host mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_store_domain (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            host VARCHAR(253) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            redirect_to_primary TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_store_domain_host (host),
            KEY idx_store_domain_store_status (store_id,status,is_primary),
            CONSTRAINT fk_store_domain_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_store_domain');
    }
}
