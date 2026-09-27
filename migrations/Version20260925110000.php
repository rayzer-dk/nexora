<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Operational shipment registry, tracking lifecycle, printable labels and return shipments.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_shipment (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            fulfillment_id BIGINT UNSIGNED NULL,
            return_request_id BIGINT UNSIGNED NULL,
            direction VARCHAR(16) NOT NULL DEFAULT 'outbound',
            provider_code VARCHAR(64) NOT NULL,
            service_type VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'registered',
            external_id VARCHAR(190) NULL,
            tracking_number VARCHAR(190) NOT NULL,
            carrier_label_url VARCHAR(2048) NULL,
            snapshot_json JSON NOT NULL,
            note TEXT NULL,
            created_by VARCHAR(190) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            cancelled_at DATETIME(6) NULL,
            delivered_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_shipment_public_id (public_id),
            UNIQUE KEY uq_shipment_tracking (store_id,provider_code,tracking_number,direction),
            KEY idx_shipment_order (order_id,id),
            KEY idx_shipment_store_status (store_id,status,updated_at),
            KEY idx_shipment_return (return_request_id,id),
            CONSTRAINT fk_shipment_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE RESTRICT,
            CONSTRAINT fk_shipment_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE,
            CONSTRAINT fk_shipment_fulfillment FOREIGN KEY (fulfillment_id) REFERENCES mc_fulfillment(id) ON DELETE SET NULL,
            CONSTRAINT fk_shipment_return FOREIGN KEY (return_request_id) REFERENCES mc_return_request(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_shipment_event (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            actor VARCHAR(190) NULL,
            payload_json JSON NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_shipment_event (shipment_id,id),
            CONSTRAINT fk_shipment_event_shipment FOREIGN KEY (shipment_id) REFERENCES mc_shipment(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_shipment_event');
        $this->addSql('DROP TABLE IF EXISTS mc_shipment');
    }
}
