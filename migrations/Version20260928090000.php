<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Built-in search index used when no external engine (Meilisearch) is configured:
 * one normalized document per product/store/language and a vocabulary for typo correction.
 */
final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SQL search documents and vocabulary for typo-tolerant, multilingual storefront search.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_search_document (
            store_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            document MEDIUMTEXT NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id, locale, product_id),
            KEY idx_search_document_product (product_id),
            CONSTRAINT fk_search_document_product FOREIGN KEY (product_id) REFERENCES mc_product (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE mc_search_term (
            store_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            term VARCHAR(64) NOT NULL,
            term_length TINYINT UNSIGNED NOT NULL,
            weight INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (store_id, locale, term),
            KEY idx_search_term_length (store_id, locale, term_length)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_search_term');
        $this->addSql('DROP TABLE mc_search_document');
    }
}
