<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer wishlist scoped by store and product.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_customer_wishlist (
            customer_id BIGINT UNSIGNED NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (customer_id, store_id, product_id),
            KEY idx_customer_wishlist_store_created (store_id, created_at),
            KEY idx_customer_wishlist_product (product_id),
            CONSTRAINT fk_customer_wishlist_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE,
            CONSTRAINT fk_customer_wishlist_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_customer_wishlist_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_customer_wishlist');
    }
}
