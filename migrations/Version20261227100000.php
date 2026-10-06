<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261227100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiscal receipts (PRRO) issued for orders.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_fiscal_receipt (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(24) NOT NULL,
            receipt_uuid CHAR(36) NOT NULL,
            fiscal_code VARCHAR(64) NULL,
            receipt_url VARCHAR(500) NULL,
            status VARCHAR(16) NOT NULL,
            total_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            error_text VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fiscal_receipt_order (order_id),
            UNIQUE KEY uq_fiscal_receipt_uuid (receipt_uuid),
            KEY idx_fiscal_receipt_status (status,updated_at),
            CONSTRAINT fk_fiscal_receipt_order FOREIGN KEY (order_id) REFERENCES mc_sales_order (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_fiscal_receipt');
    }
}
