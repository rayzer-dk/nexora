<?php

declare(strict_types=1);
namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Reusable layout snippets for visual builder.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_layout_snippet (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            layout_type VARCHAR(32) NOT NULL,
            payload JSON NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_layout_snippet_store_name (store_id,name),
            KEY idx_layout_snippet_store_type (store_id,layout_type,id),
            CONSTRAINT fk_layout_snippet_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE IF EXISTS mc_layout_snippet'); }
}
