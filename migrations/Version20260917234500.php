<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Managed search synonym groups for store/locale-aware catalog search.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_search_synonym_group (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            label VARCHAR(190) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_search_synonym_group_public_id (public_id),
            KEY idx_search_synonym_group_store_locale (store_id, locale, status, id),
            CONSTRAINT fk_search_synonym_group_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_search_synonym_term (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            term VARCHAR(190) NOT NULL,
            term_hash BINARY(32) NOT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_search_synonym_group_term_hash (group_id, term_hash),
            KEY idx_search_synonym_term_hash (term_hash, group_id),
            CONSTRAINT fk_search_synonym_term_group FOREIGN KEY (group_id) REFERENCES mc_search_synonym_group(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_search_synonym_term');
        $this->addSql('DROP TABLE IF EXISTS mc_search_synonym_group');
    }
}
