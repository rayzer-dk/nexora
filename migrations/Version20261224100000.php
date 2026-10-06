<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261224100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product extras: own canonical URL, tags, Google Merchant custom labels and a separate H1 for articles.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_product_extra (
            product_id BIGINT UNSIGNED NOT NULL,
            canonical_url VARCHAR(500) NULL,
            tags VARCHAR(500) NULL,
            custom_label_0 VARCHAR(100) NULL,
            custom_label_1 VARCHAR(100) NULL,
            custom_label_2 VARCHAR(100) NULL,
            custom_label_3 VARCHAR(100) NULL,
            custom_label_4 VARCHAR(100) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (product_id),
            CONSTRAINT fk_product_extra_product FOREIGN KEY (product_id) REFERENCES mc_product (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql('ALTER TABLE mc_content_translation ADD COLUMN h1 VARCHAR(255) NULL AFTER title');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_content_translation DROP COLUMN h1');
        $this->addSql('DROP TABLE mc_product_extra');
    }
}
