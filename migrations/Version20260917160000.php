<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optional forum/community module with moderation-first guest posting.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_forum_board (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(160) NOT NULL,
            name VARCHAR(190) NOT NULL,
            description VARCHAR(1000) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_board_public_id (public_id),
            UNIQUE KEY uq_forum_board_store_slug (store_id, slug),
            KEY idx_forum_board_store_status_sort (store_id, status, sort_order, id),
            CONSTRAINT fk_forum_board_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_topic (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            board_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(240) NOT NULL,
            slug VARCHAR(240) NOT NULL,
            author_name VARCHAR(120) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            published_at DATETIME(6) NULL,
            last_post_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_topic_public_id (public_id),
            KEY idx_forum_topic_board_status_activity (board_id, status, is_pinned, last_post_at, id),
            KEY idx_forum_topic_status_created (status, created_at),
            CONSTRAINT fk_forum_topic_board FOREIGN KEY (board_id) REFERENCES mc_forum_board(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_post (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            topic_id BIGINT UNSIGNED NOT NULL,
            author_name VARCHAR(120) NOT NULL,
            body_text MEDIUMTEXT NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            published_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_post_public_id (public_id),
            KEY idx_forum_post_topic_status_id (topic_id, status, id),
            KEY idx_forum_post_status_created (status, created_at),
            CONSTRAINT fk_forum_post_topic FOREIGN KEY (topic_id) REFERENCES mc_forum_topic(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_forum_post');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_topic');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_board');
    }
}
