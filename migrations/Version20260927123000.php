<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum v2: unified customer identity, subscriptions, reactions, reports and moderation metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_forum_topic
            ADD customer_id BIGINT UNSIGNED NULL AFTER board_id,
            ADD views_count BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_locked,
            ADD KEY idx_forum_topic_customer (customer_id, created_at),
            ADD CONSTRAINT fk_forum_topic_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL");

        $this->addSql("ALTER TABLE mc_forum_post
            ADD customer_id BIGINT UNSIGNED NULL AFTER topic_id,
            ADD edited_at DATETIME(6) NULL AFTER published_at,
            ADD edit_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER edited_at,
            ADD KEY idx_forum_post_customer (customer_id, created_at),
            ADD CONSTRAINT fk_forum_post_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL");

        $this->addSql("CREATE TABLE mc_forum_subscription (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            topic_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_subscription (topic_id, customer_id),
            KEY idx_forum_subscription_customer (store_id, customer_id, created_at),
            CONSTRAINT fk_forum_subscription_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_subscription_topic FOREIGN KEY (topic_id) REFERENCES mc_forum_topic(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_subscription_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_reaction (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            reaction VARCHAR(24) NOT NULL DEFAULT 'like',
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_reaction (post_id, customer_id, reaction),
            KEY idx_forum_reaction_post (post_id, reaction),
            CONSTRAINT fk_forum_reaction_post FOREIGN KEY (post_id) REFERENCES mc_forum_post(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_reaction_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_report (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(32) NOT NULL,
            details VARCHAR(1000) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'open',
            created_at DATETIME(6) NOT NULL,
            resolved_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_forum_report_customer_post (post_id, customer_id),
            KEY idx_forum_report_store_status (store_id, status, created_at),
            CONSTRAINT fk_forum_report_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_report_post FOREIGN KEY (post_id) REFERENCES mc_forum_post(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_report_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_forum_post_revision (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            editor_customer_id BIGINT UNSIGNED NULL,
            body_text MEDIUMTEXT NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_forum_post_revision_post (post_id, id),
            CONSTRAINT fk_forum_post_revision_post FOREIGN KEY (post_id) REFERENCES mc_forum_post(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_post_revision_customer FOREIGN KEY (editor_customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_forum_post_revision');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_report');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_reaction');
        $this->addSql('DROP TABLE IF EXISTS mc_forum_subscription');
        $this->addSql('ALTER TABLE mc_forum_post DROP FOREIGN KEY fk_forum_post_customer, DROP INDEX idx_forum_post_customer, DROP COLUMN edit_count, DROP COLUMN edited_at, DROP COLUMN customer_id');
        $this->addSql('ALTER TABLE mc_forum_topic DROP FOREIGN KEY fk_forum_topic_customer, DROP INDEX idx_forum_topic_customer, DROP COLUMN views_count, DROP COLUMN customer_id');
    }
}
