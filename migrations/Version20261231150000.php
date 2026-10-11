<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order connections: orders are sent to a CRM, an accounting program or any address, with a body template of the shop owner and a delivery log.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql('CREATE TABLE mc_crm_connection (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            preset VARCHAR(32) NOT NULL DEFAULT \'json\',
            url VARCHAR(1000) NOT NULL,
            method VARCHAR(8) NOT NULL DEFAULT \'POST\',
            headers_enc TEXT NULL,
            signing_secret VARCHAR(64) NOT NULL,
            body_template MEDIUMTEXT NOT NULL,
            events JSON NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_status VARCHAR(16) NULL,
            last_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_crm_connection_store (store_id, enabled),
            CONSTRAINT fk_crm_connection_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )' . $t);
        $this->addSql('CREATE TABLE mc_crm_delivery (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_id BIGINT UNSIGNED NOT NULL,
            order_number VARCHAR(32) NULL,
            event VARCHAR(24) NOT NULL,
            status VARCHAR(16) NOT NULL,
            http_status SMALLINT UNSIGNED NULL,
            response_excerpt VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_crm_delivery_connection (connection_id, id),
            CONSTRAINT fk_crm_delivery_connection FOREIGN KEY (connection_id) REFERENCES mc_crm_connection(id) ON DELETE CASCADE
        )' . $t);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_crm_delivery');
        $this->addSql('DROP TABLE mc_crm_connection');
    }
}
