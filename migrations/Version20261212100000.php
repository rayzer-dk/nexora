<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261212100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Broken links and pictures found by the daily content check.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_link_check_issue (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            issue_hash CHAR(40) NOT NULL,
            source_type VARCHAR(24) NOT NULL,
            source_label VARCHAR(255) NOT NULL,
            source_url VARCHAR(255) NOT NULL,
            kind VARCHAR(8) NOT NULL,
            target VARCHAR(1000) NOT NULL,
            reason VARCHAR(24) NOT NULL,
            locale VARCHAR(16) DEFAULT NULL,
            ignored TINYINT(1) NOT NULL DEFAULT 0,
            first_seen_at DATETIME(6) NOT NULL,
            last_seen_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_link_check_hash (issue_hash),
            KEY idx_link_check_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_link_check_issue');
    }
}
