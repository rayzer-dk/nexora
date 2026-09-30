<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Form builder: forms and their submissions.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql("CREATE TABLE mc_form (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(120) NOT NULL,
            name VARCHAR(190) NOT NULL,
            locale VARCHAR(12) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            intro TEXT NULL,
            submit_label VARCHAR(80) NULL,
            success_message VARCHAR(500) NULL,
            notify_email VARCHAR(190) NULL,
            fields JSON NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_form_slug (store_id, slug),
            CONSTRAINT fk_form_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )" . $t);
        $this->addSql("CREATE TABLE mc_form_submission (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            answers JSON NOT NULL,
            locale VARCHAR(12) NULL,
            ip_hash BINARY(32) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'new',
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_form_submission_form (form_id, created_at),
            KEY idx_form_submission_created (created_at),
            CONSTRAINT fk_form_submission_form FOREIGN KEY (form_id) REFERENCES mc_form(id) ON DELETE CASCADE
        )" . $t);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_form_submission');
        $this->addSql('DROP TABLE IF EXISTS mc_form');
    }
}
