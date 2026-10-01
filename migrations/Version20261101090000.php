<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product videos: a link (YouTube, Vimeo or a direct video file) shown in the product gallery with a preview picture.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_product_video (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(16) NOT NULL,
            video_ref VARCHAR(190) NOT NULL,
            url VARCHAR(500) NOT NULL,
            title VARCHAR(190) NULL,
            placement VARCHAR(8) NOT NULL DEFAULT 'end',
            sort_order INT NOT NULL DEFAULT 0,
            poster_asset_id BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_product_video_ref (product_id, provider, video_ref),
            KEY idx_product_video_order (product_id, placement, sort_order),
            CONSTRAINT fk_product_video_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
            CONSTRAINT fk_product_video_poster FOREIGN KEY (poster_asset_id) REFERENCES mc_media_asset(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_product_video');
    }
}
