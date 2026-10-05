<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261211100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Own redirects (old address → new address, e.g. after a move from another shop engine) and a log of missing pages.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_custom_redirect (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            source_hash CHAR(40) NOT NULL,
            source_path VARCHAR(500) NOT NULL,
            target_url VARCHAR(1000) NOT NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_hit_at DATETIME(6) DEFAULT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_custom_redirect_source (source_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_not_found_log (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            path_hash CHAR(40) NOT NULL,
            path VARCHAR(500) NOT NULL,
            hit_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
            referer VARCHAR(500) DEFAULT NULL,
            first_seen_at DATETIME(6) NOT NULL,
            last_seen_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_not_found_path (path_hash),
            KEY idx_not_found_hits (hit_count)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_not_found_log');
        $this->addSql('DROP TABLE IF EXISTS mc_custom_redirect');
    }
}
