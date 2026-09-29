<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store-scoped Media Library ownership plus store scope for integration and marketing jobs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_store_media_asset (
            store_id BIGINT UNSIGNED NOT NULL,
            asset_id BIGINT UNSIGNED NOT NULL,
            folder_id BIGINT UNSIGNED NULL,
            alt_text VARCHAR(500) NULL,
            title VARCHAR(500) NULL,
            focal_x DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            focal_y DECIMAL(5,2) NOT NULL DEFAULT 50.00,
            tags_json JSON NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id, asset_id),
            KEY idx_store_media_folder (store_id, folder_id, asset_id),
            KEY idx_store_media_asset (asset_id, store_id),
            CONSTRAINT fk_store_media_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_store_media_asset FOREIGN KEY (asset_id) REFERENCES mc_media_asset(id) ON DELETE CASCADE,
            CONSTRAINT fk_store_media_folder FOREIGN KEY (folder_id) REFERENCES mc_media_folder(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("INSERT IGNORE INTO mc_store_media_asset
            (store_id,asset_id,folder_id,alt_text,title,focal_x,focal_y,tags_json,created_at,updated_at)
            SELECT DISTINCT sp.store_id,ma.id,
                CASE WHEN mf.store_id=sp.store_id THEN mam.folder_id ELSE NULL END,
                mam.alt_text,mam.title,COALESCE(mam.focal_x,50),COALESCE(mam.focal_y,50),
                COALESCE(mam.tags_json,JSON_ARRAY()),ma.created_at,COALESCE(mam.updated_at,ma.created_at)
            FROM mc_media_asset ma
            JOIN mc_product_media pm ON pm.media_asset_id=ma.id
            JOIN mc_store_product sp ON sp.product_id=pm.product_id
            LEFT JOIN mc_media_asset_meta mam ON mam.asset_id=ma.id
            LEFT JOIN mc_media_folder mf ON mf.id=mam.folder_id");

        $this->addSql("INSERT IGNORE INTO mc_store_media_asset
            (store_id,asset_id,folder_id,alt_text,title,focal_x,focal_y,tags_json,created_at,updated_at)
            SELECT mf.store_id,ma.id,mam.folder_id,mam.alt_text,mam.title,
                COALESCE(mam.focal_x,50),COALESCE(mam.focal_y,50),
                COALESCE(mam.tags_json,JSON_ARRAY()),ma.created_at,COALESCE(mam.updated_at,ma.created_at)
            FROM mc_media_asset ma
            JOIN mc_media_asset_meta mam ON mam.asset_id=ma.id
            JOIN mc_media_folder mf ON mf.id=mam.folder_id");

        $this->addSql("INSERT IGNORE INTO mc_store_media_asset
            (store_id,asset_id,folder_id,alt_text,title,focal_x,focal_y,tags_json,created_at,updated_at)
            SELECT s.id,ma.id,NULL,mam.alt_text,mam.title,
                COALESCE(mam.focal_x,50),COALESCE(mam.focal_y,50),
                COALESCE(mam.tags_json,JSON_ARRAY()),ma.created_at,COALESCE(mam.updated_at,ma.created_at)
            FROM mc_media_asset ma
            JOIN (SELECT id FROM mc_store WHERE status='active' ORDER BY id LIMIT 1) s
            LEFT JOIN mc_media_asset_meta mam ON mam.asset_id=ma.id
            LEFT JOIN mc_store_media_asset sma ON sma.asset_id=ma.id
            WHERE sma.asset_id IS NULL");

        $this->addSql("ALTER TABLE mc_integration_sync_queue
            ADD COLUMN store_id BIGINT UNSIGNED NULL AFTER id,
            ADD KEY idx_integration_sync_store (store_id,integration_code,status,updated_at),
            ADD CONSTRAINT fk_integration_sync_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE");

        $this->addSql("UPDATE mc_integration_sync_queue
            SET store_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.store_id')) AS UNSIGNED)
            WHERE JSON_UNQUOTE(JSON_EXTRACT(payload,'$.store_id')) REGEXP '^[0-9]+$'");

        $this->addSql("UPDATE mc_integration_sync_queue
            SET store_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.payload.store_id')) AS UNSIGNED)
            WHERE store_id IS NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.payload.store_id')) REGEXP '^[0-9]+$'");

        $this->addSql("ALTER TABLE mc_marketing_delivery
            ADD COLUMN store_id BIGINT UNSIGNED NULL AFTER id,
            ADD KEY idx_marketing_delivery_store (store_id,provider,status,updated_at),
            ADD CONSTRAINT fk_marketing_delivery_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE");

        $this->addSql("UPDATE mc_marketing_delivery md
            JOIN mc_integration_sync_queue q
              ON q.integration_code='marketing'
             AND JSON_UNQUOTE(JSON_EXTRACT(q.payload,'$.event_id'))=md.event_id
            SET md.store_id=q.store_id
            WHERE q.store_id IS NOT NULL");

        $this->addSql("CREATE TABLE mc_store_customer (
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            customer_group_code VARCHAR(64) NOT NULL DEFAULT 'default',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (store_id,customer_id),
            KEY idx_store_customer_customer (customer_id,store_id),
            KEY idx_store_customer_group (store_id,customer_group_code,customer_id),
            CONSTRAINT fk_store_customer_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_store_customer_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("INSERT IGNORE INTO mc_store_customer
            (store_id,customer_id,customer_group_code,created_at,updated_at)
            SELECT DISTINCT o.store_id,c.id,c.customer_group_code,
                LEAST(c.created_at,MIN(o.created_at)),GREATEST(c.updated_at,MAX(o.updated_at))
            FROM mc_customer c
            JOIN mc_sales_order o ON o.customer_id=c.id
            GROUP BY o.store_id,c.id,c.customer_group_code,c.created_at,c.updated_at");

        $this->addSql("INSERT IGNORE INTO mc_store_customer
            (store_id,customer_id,customer_group_code,created_at,updated_at)
            SELECT DISTINCT ca.store_id,c.id,c.customer_group_code,
                LEAST(c.created_at,MIN(ca.created_at)),GREATEST(c.updated_at,MAX(ca.updated_at))
            FROM mc_customer c
            JOIN mc_cart ca ON ca.customer_id=c.id
            GROUP BY ca.store_id,c.id,c.customer_group_code,c.created_at,c.updated_at");

        $this->addSql("INSERT IGNORE INTO mc_store_customer
            (store_id,customer_id,customer_group_code,created_at,updated_at)
            SELECT s.id,c.id,c.customer_group_code,c.created_at,c.updated_at
            FROM mc_customer c
            JOIN (SELECT id FROM mc_store WHERE status='active' ORDER BY id LIMIT 1) s
            LEFT JOIN mc_store_customer sc ON sc.customer_id=c.id
            WHERE sc.customer_id IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_marketing_delivery
            DROP FOREIGN KEY fk_marketing_delivery_store,
            DROP INDEX idx_marketing_delivery_store,
            DROP COLUMN store_id");

        $this->addSql("ALTER TABLE mc_integration_sync_queue
            DROP FOREIGN KEY fk_integration_sync_store,
            DROP INDEX idx_integration_sync_store,
            DROP COLUMN store_id");

        $this->addSql('DROP TABLE IF EXISTS mc_store_customer');
        $this->addSql('DROP TABLE IF EXISTS mc_store_media_asset');
    }
}
