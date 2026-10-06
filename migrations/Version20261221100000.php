<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261221100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forum: reports about a member and per-topic moderators.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_forum_report MODIFY post_id BIGINT UNSIGNED NULL, ADD target_customer_id BIGINT UNSIGNED NULL AFTER post_id');
        $this->addSql('CREATE UNIQUE INDEX uq_forum_report_member ON mc_forum_report (target_customer_id, customer_id)');
        $this->addSql('CREATE TABLE mc_forum_topic_moderator (
            topic_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (topic_id, customer_id),
            INDEX idx_forum_topic_moderator_customer (customer_id),
            CONSTRAINT fk_forum_topic_moderator_topic FOREIGN KEY (topic_id) REFERENCES mc_forum_topic(id) ON DELETE CASCADE,
            CONSTRAINT fk_forum_topic_moderator_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_forum_topic_moderator');
        $this->addSql('DROP INDEX uq_forum_report_member ON mc_forum_report');
        $this->addSql('ALTER TABLE mc_forum_report DROP COLUMN target_customer_id, MODIFY post_id BIGINT UNSIGNED NOT NULL');
    }
}
