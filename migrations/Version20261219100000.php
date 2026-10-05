<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261219100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum: subforums, solved answers, member avatars, image attachments and polls.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_forum_board ADD parent_id BIGINT NULL AFTER store_id');
        $this->addSql('CREATE INDEX idx_forum_board_parent ON mc_forum_board (store_id, parent_id)');
        $this->addSql('ALTER TABLE mc_forum_topic ADD solved_post_id BIGINT NULL');
        $this->addSql('ALTER TABLE mc_forum_profile ADD avatar_url VARCHAR(500) NULL');
        $this->addSql('CREATE TABLE mc_forum_attachment (
            id BIGINT AUTO_INCREMENT NOT NULL,
            post_id BIGINT NOT NULL,
            customer_id BIGINT NOT NULL,
            storage_path VARCHAR(500) NOT NULL,
            original_name VARCHAR(190) NOT NULL,
            width INT NOT NULL,
            height INT NOT NULL,
            size_bytes INT NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            INDEX idx_forum_attachment_post (post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE mc_forum_poll (
            id BIGINT AUTO_INCREMENT NOT NULL,
            topic_id BIGINT NOT NULL,
            question VARCHAR(240) NOT NULL,
            is_multiple TINYINT(1) NOT NULL DEFAULT 0,
            closes_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX uniq_forum_poll_topic (topic_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE mc_forum_poll_option (
            id BIGINT AUTO_INCREMENT NOT NULL,
            poll_id BIGINT NOT NULL,
            label VARCHAR(190) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            INDEX idx_forum_poll_option_poll (poll_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE mc_forum_poll_vote (
            id BIGINT AUTO_INCREMENT NOT NULL,
            poll_id BIGINT NOT NULL,
            option_id BIGINT NOT NULL,
            customer_id BIGINT NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE INDEX uniq_forum_poll_vote (poll_id, option_id, customer_id),
            INDEX idx_forum_poll_vote_customer (poll_id, customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_forum_poll_vote');
        $this->addSql('DROP TABLE mc_forum_poll_option');
        $this->addSql('DROP TABLE mc_forum_poll');
        $this->addSql('DROP TABLE mc_forum_attachment');
        $this->addSql('ALTER TABLE mc_forum_profile DROP COLUMN avatar_url');
        $this->addSql('ALTER TABLE mc_forum_topic DROP COLUMN solved_post_id');
        $this->addSql('DROP INDEX idx_forum_board_parent ON mc_forum_board');
        $this->addSql('ALTER TABLE mc_forum_board DROP COLUMN parent_id');
    }
}
