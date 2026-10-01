<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Storefront contact details (address, hours, social links, map) and self-pickup points.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS mc_storefront_contact (
            store_id BIGINT UNSIGNED NOT NULL,
            address VARCHAR(500) NULL,
            working_hours VARCHAR(1000) NULL,
            phone_secondary VARCHAR(64) NULL,
            social_links TEXT NULL,
            map_lat DECIMAL(9,6) NULL,
            map_lng DECIMAL(9,6) NULL,
            map_zoom TINYINT UNSIGNED NOT NULL DEFAULT 16,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id),
            CONSTRAINT fk_storefront_contact_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("CREATE TABLE IF NOT EXISTS mc_pickup_point (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            city VARCHAR(190) NULL,
            address VARCHAR(500) NOT NULL,
            working_hours VARCHAR(500) NULL,
            phone VARCHAR(64) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_pickup_point_store (store_id,enabled,sort_order),
            CONSTRAINT fk_pickup_point_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Existing stores get one pickup point from the address they already keep in the store profile, so the new
        // "Self pickup" option works immediately after the upgrade; stores without an address simply see no pickup point.
        $this->addSql("INSERT INTO mc_pickup_point (store_id,name,city,address,working_hours,phone,sort_order,enabled,created_at,updated_at)
            SELECT p.store_id,COALESCE(NULLIF(p.legal_name,''),s.name),NULL,p.registration_address,NULL,NULLIF(p.phone,''),0,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)
            FROM mc_store_profile p JOIN mc_store s ON s.id=p.store_id
            WHERE p.registration_address IS NOT NULL AND TRIM(p.registration_address)<>''
              AND NOT EXISTS (SELECT 1 FROM mc_pickup_point x WHERE x.store_id=p.store_id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_pickup_point');
        $this->addSql('DROP TABLE IF EXISTS mc_storefront_contact');
    }
}
