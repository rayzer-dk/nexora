<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261208100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes of the shop team on a customer (customer card in the admin).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_customer_note (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            author VARCHAR(190) DEFAULT NULL,
            body TEXT NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_customer_note_customer (customer_id, created_at),
            CONSTRAINT fk_customer_note_customer FOREIGN KEY (customer_id) REFERENCES mc_customer (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_customer_note');
    }
}
