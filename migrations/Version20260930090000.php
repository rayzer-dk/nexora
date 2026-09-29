<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Electronic contract withdrawal notices (Directive (EU) 2023/2673 withdrawal function).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_withdrawal_notice (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            order_reference VARCHAR(64) NOT NULL,
            customer_name VARCHAR(190) NOT NULL,
            email VARCHAR(320) NOT NULL,
            email_normalized VARCHAR(320) NOT NULL,
            scope_note VARCHAR(1000) NULL,
            locale VARCHAR(16) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'received',
            ip_hash CHAR(64) NOT NULL,
            received_at DATETIME(6) NOT NULL,
            acknowledged_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_withdrawal_public (public_id),
            KEY idx_withdrawal_store_received (store_id, received_at),
            KEY idx_withdrawal_email (email_normalized)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_withdrawal_notice');
    }
}
