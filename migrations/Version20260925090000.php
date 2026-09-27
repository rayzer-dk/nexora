<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Immutable order documents and seller billing details.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_store_profile ADD tax_number VARCHAR(128) NULL AFTER registration_number, ADD vat_number VARCHAR(128) NULL AFTER tax_number, ADD iban VARCHAR(64) NULL AFTER registration_address, ADD bank_name VARCHAR(255) NULL AFTER iban");
        $this->addSql("CREATE TABLE mc_order_document_sequence (store_id BIGINT UNSIGNED NOT NULL, document_type VARCHAR(32) NOT NULL, sequence_year SMALLINT UNSIGNED NOT NULL, next_number BIGINT UNSIGNED NOT NULL DEFAULT 1, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (store_id,document_type,sequence_year), CONSTRAINT fk_order_document_sequence_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_order_document (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, public_id BINARY(16) NOT NULL, store_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NOT NULL, payment_refund_id BIGINT UNSIGNED NULL, issue_key VARCHAR(190) NOT NULL, document_type VARCHAR(32) NOT NULL, document_number VARCHAR(64) NOT NULL, sequence_year SMALLINT UNSIGNED NOT NULL, sequence_number BIGINT UNSIGNED NOT NULL, locale VARCHAR(16) NOT NULL, currency CHAR(3) NOT NULL, snapshot_json JSON NOT NULL, issued_at DATETIME(6) NOT NULL, created_by VARCHAR(190) NULL, PRIMARY KEY (id), UNIQUE KEY uq_order_document_public_id (public_id), UNIQUE KEY uq_order_document_issue_key (store_id,issue_key), UNIQUE KEY uq_order_document_number (store_id,document_type,document_number), KEY idx_order_document_order (order_id,issued_at), CONSTRAINT fk_order_document_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE RESTRICT, CONSTRAINT fk_order_document_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE, CONSTRAINT fk_order_document_refund FOREIGN KEY (payment_refund_id) REFERENCES mc_payment_refund(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_order_document');
        $this->addSql('DROP TABLE IF EXISTS mc_order_document_sequence');
        $this->addSql('ALTER TABLE mc_store_profile DROP COLUMN bank_name, DROP COLUMN iban, DROP COLUMN vat_number, DROP COLUMN tax_number');
    }
}
