<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Show every uploaded image/video in the store media library (backfill of assets that were never linked to a store).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO mc_store_media_asset
            (store_id,asset_id,folder_id,alt_text,title,focal_x,focal_y,tags_json,created_at,updated_at)
            SELECT (SELECT MIN(id) FROM mc_store),ma.id,NULL,NULL,NULL,50,50,JSON_ARRAY(),ma.created_at,ma.created_at
            FROM mc_media_asset ma
            WHERE (ma.mime_type LIKE 'image/%' OR ma.mime_type LIKE 'video/%')
              AND (SELECT MIN(id) FROM mc_store) IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM mc_store_media_asset x WHERE x.asset_id=ma.id)");
    }

    public function down(Schema $schema): void
    {
        // Data backfill only; nothing to undo.
    }
}
