<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store-scoped Media Library ownership and metadata while preserving globally deduplicated media files.';
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_store_media_asset');
    }
}
