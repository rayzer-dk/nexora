<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Operational returns/RMA, product Q&A and saved-cart foundations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_return_request (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'requested',
            reason_code VARCHAR(64) NOT NULL,
            customer_note TEXT NULL,
            admin_note TEXT NULL,
            resolution VARCHAR(32) NULL,
            return_tracking_number VARCHAR(190) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            approved_at DATETIME(6) NULL,
            received_at DATETIME(6) NULL,
            resolved_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_return_public_id (public_id),
            KEY idx_return_store_status_created (store_id,status,created_at),
            KEY idx_return_order (order_id,id),
            KEY idx_return_customer (customer_id,created_at),
            CONSTRAINT fk_return_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE RESTRICT,
            CONSTRAINT fk_return_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE,
            CONSTRAINT fk_return_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_return_item (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            return_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            quantity DECIMAL(18,6) UNSIGNED NOT NULL,
            reason_code VARCHAR(64) NULL,
            condition_code VARCHAR(64) NULL,
            resolution VARCHAR(32) NULL,
            refund_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            restock TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_return_item (return_id,order_item_id),
            KEY idx_return_item_order_item (order_item_id),
            CONSTRAINT fk_return_item_return FOREIGN KEY (return_id) REFERENCES mc_return_request(id) ON DELETE CASCADE,
            CONSTRAINT fk_return_item_order_item FOREIGN KEY (order_item_id) REFERENCES mc_sales_order_item(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_return_event (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            return_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            actor_type VARCHAR(32) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            payload JSON NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_return_event_return (return_id,id),
            CONSTRAINT fk_return_event_return FOREIGN KEY (return_id) REFERENCES mc_return_request(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_product_question (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            locale VARCHAR(16) NOT NULL,
            author_name VARCHAR(190) NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            created_at DATETIME(6) NOT NULL,
            answered_at DATETIME(6) NULL,
            published_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_product_question_public_id (public_id),
            KEY idx_question_product_status_created (product_id,status,created_at),
            KEY idx_question_store_status_created (store_id,status,created_at),
            CONSTRAINT fk_question_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_question_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
            CONSTRAINT fk_question_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE SET NULL,
            CONSTRAINT fk_question_locale FOREIGN KEY (locale) REFERENCES mc_locale(code) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_saved_cart (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            currency CHAR(3) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_saved_cart_public_id (public_id),
            KEY idx_saved_cart_customer (store_id,customer_id,status,updated_at),
            CONSTRAINT fk_saved_cart_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE,
            CONSTRAINT fk_saved_cart_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->addSql("CREATE TABLE mc_saved_cart_item (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            saved_cart_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            quantity DECIMAL(20,6) UNSIGNED NOT NULL,
            snapshot JSON NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_saved_cart_variant (saved_cart_id,variant_id),
            CONSTRAINT fk_saved_cart_item_cart FOREIGN KEY (saved_cart_id) REFERENCES mc_saved_cart(id) ON DELETE CASCADE,
            CONSTRAINT fk_saved_cart_item_product FOREIGN KEY (product_id) REFERENCES mc_product(id) ON DELETE CASCADE,
            CONSTRAINT fk_saved_cart_item_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mc_saved_cart_item');
        $this->addSql('DROP TABLE IF EXISTS mc_saved_cart');
        $this->addSql('DROP TABLE IF EXISTS mc_product_question');
        $this->addSql('DROP TABLE IF EXISTS mc_return_event');
        $this->addSql('DROP TABLE IF EXISTS mc_return_item');
        $this->addSql('DROP TABLE IF EXISTS mc_return_request');
    }
}
