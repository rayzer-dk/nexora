<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Complete product feedback lifecycle: merchant replies, review helpful votes and review media indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_product_review ADD merchant_reply TEXT NULL AFTER body, ADD merchant_replied_at DATETIME(6) NULL AFTER merchant_reply");
        $this->addSql("CREATE TABLE mc_review_helpful_vote (
            review_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (review_id,customer_id),
            KEY idx_review_helpful_customer (customer_id,created_at),
            CONSTRAINT fk_review_helpful_review FOREIGN KEY (review_id) REFERENCES mc_product_review(id) ON DELETE CASCADE,
            CONSTRAINT fk_review_helpful_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql('CREATE INDEX idx_review_media_media ON mc_review_media (media_id,review_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_review_media_media ON mc_review_media');
        $this->addSql('DROP TABLE IF EXISTS mc_review_helpful_vote');
        $this->addSql('ALTER TABLE mc_product_review DROP COLUMN merchant_replied_at, DROP COLUMN merchant_reply');
    }
}
