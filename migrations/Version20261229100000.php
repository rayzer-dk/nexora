<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261229100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum: member warnings and the moderator action log.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS mc_forum_warning (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            points TINYINT UNSIGNED NOT NULL DEFAULT 1,
            actor VARCHAR(190) NOT NULL DEFAULT 'admin',
            expires_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_forum_warning_member (store_id, customer_id, revoked_at, expires_at),
            CONSTRAINT fk_forum_warning_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_warning_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE IF NOT EXISTS mc_forum_mod_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            actor VARCHAR(190) NOT NULL,
            action VARCHAR(48) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            topic_id BIGINT UNSIGNED NULL,
            post_id BIGINT UNSIGNED NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_forum_mod_log_store (store_id, id),
            CONSTRAINT fk_forum_mod_log_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_forum_mod_log');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_warning');
    }
}
