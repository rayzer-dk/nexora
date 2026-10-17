<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270101010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI batch: background translation batches with one job per entity and language; glossary of terms that are kept or translated one fixed way.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql("CREATE TABLE mc_ai_batch (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(36) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(16) NOT NULL,
            options_json JSON NOT NULL,
            status VARCHAR(16) NOT NULL,
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ai_batch_public (public_id),
            KEY idx_ai_batch_store_status (store_id, status),
            CONSTRAINT fk_ai_batch_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_ai_batch_job (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(16) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            locale VARCHAR(16) NOT NULL,
            task VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(255) NULL,
            result_json JSON NULL,
            previous_json JSON NULL,
            processed_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            KEY idx_ai_batch_job_batch_status (batch_id, status),
            KEY idx_ai_batch_job_store_status (store_id, status, id),
            CONSTRAINT fk_ai_batch_job_batch FOREIGN KEY (batch_id) REFERENCES mc_ai_batch(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_ai_glossary (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            term VARCHAR(190) NOT NULL,
            mode VARCHAR(8) NOT NULL,
            translations_json JSON NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ai_glossary_term (store_id, term),
            CONSTRAINT fk_ai_glossary_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_ai_glossary');
        $this->addSql('DROP TABLE IF EXISTS mc_ai_batch_job');
        $this->addSql('DROP TABLE IF EXISTS mc_ai_batch');
    }
}
