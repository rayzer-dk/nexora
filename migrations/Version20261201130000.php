<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261201130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reusable campaign templates (name, subject, body, format) per store.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_campaign_template (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            body_format VARCHAR(12) NOT NULL DEFAULT 'text',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_campaign_template_name (store_id, name),
            CONSTRAINT fk_campaign_template_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_campaign_template');
    }
}
