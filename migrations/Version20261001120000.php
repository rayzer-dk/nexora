<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Live chat widget settings (Tawk.to, Jivo, Crisp, Chatwoot).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_chat_widget (
            store_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(16) NOT NULL DEFAULT 'none',
            widget_id VARCHAR(190) NOT NULL DEFAULT '',
            base_url VARCHAR(190) NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_chat_widget_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_chat_widget');
    }
}
