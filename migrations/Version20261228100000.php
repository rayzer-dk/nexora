<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261228100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiscal receipts: sale and return receipts of one order.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_fiscal_receipt ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'sale' AFTER order_id, ADD COLUMN refund_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER kind");
        $this->addSql('ALTER TABLE mc_fiscal_receipt DROP INDEX uq_fiscal_receipt_order, ADD UNIQUE KEY uq_fiscal_receipt_order_kind (order_id,kind,refund_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM mc_fiscal_receipt WHERE kind<>'sale'");
        $this->addSql('ALTER TABLE mc_fiscal_receipt DROP INDEX uq_fiscal_receipt_order_kind, ADD UNIQUE KEY uq_fiscal_receipt_order (order_id), DROP COLUMN refund_id, DROP COLUMN kind');
    }
}
