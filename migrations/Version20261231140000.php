<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migration from files: a queue of product photos that are downloaded from the old shop after the import.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mc_import_image_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            url VARCHAR(1500) NOT NULL,
            alt_text VARCHAR(255) NULL,
            role VARCHAR(16) NOT NULL DEFAULT \'gallery\',
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT \'pending\',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(255) NULL,
            created_at DATETIME(6) NOT NULL,
            processed_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            KEY idx_import_image_status (status, id),
            KEY idx_import_image_product (product_id),
            CONSTRAINT fk_import_image_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_import_image_queue');
    }
}
