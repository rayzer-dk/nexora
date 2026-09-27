<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add store-scoped forum bans without affecting commerce customer accounts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_forum_ban (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            expires_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_forum_ban_active (store_id, customer_id, revoked_at, expires_at),
            CONSTRAINT fk_forum_ban_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_ban_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_forum_ban');
    }
}
