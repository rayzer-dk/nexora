<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add B2B companies, members, price lists, quantity tiers and order approval/credit terms.'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_b2b_company (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            legal_name VARCHAR(255) NULL,
            tax_id VARCHAR(64) NULL,
            vat_id VARCHAR(64) NULL,
            currency CHAR(3) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            credit_limit_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            approval_threshold_minor BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_b2b_company_public (public_id),
            KEY idx_b2b_company_store_status (store_id,status,name),
            KEY idx_b2b_company_tax (store_id,tax_id),
            CONSTRAINT fk_b2b_company_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_b2b_company_member (
            company_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'buyer',
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            spending_limit_minor BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (company_id,customer_id),
            KEY idx_b2b_member_customer (customer_id,status),
            CONSTRAINT fk_b2b_member_company FOREIGN KEY (company_id) REFERENCES mc_b2b_company(id) ON DELETE CASCADE,
            CONSTRAINT fk_b2b_member_customer FOREIGN KEY (customer_id) REFERENCES mc_customer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_b2b_price_list (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id BINARY(16) NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            currency CHAR(3) NOT NULL,
            priority INT NOT NULL DEFAULT 100,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            starts_at DATETIME(6) NULL,
            ends_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_b2b_price_list_public (public_id),
            KEY idx_b2b_price_list_store (store_id,status,priority,id),
            CONSTRAINT fk_b2b_price_list_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_b2b_price_list_company (
            price_list_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (price_list_id,company_id),
            KEY idx_b2b_pl_company (company_id,price_list_id),
            CONSTRAINT fk_b2b_plc_list FOREIGN KEY (price_list_id) REFERENCES mc_b2b_price_list(id) ON DELETE CASCADE,
            CONSTRAINT fk_b2b_plc_company FOREIGN KEY (company_id) REFERENCES mc_b2b_company(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("CREATE TABLE mc_b2b_price_tier (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            price_list_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            min_quantity DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            max_quantity DECIMAL(18,6) NULL,
            amount_minor BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_b2b_tier (price_list_id,variant_id,min_quantity),
            KEY idx_b2b_tier_variant (variant_id,min_quantity,max_quantity),
            CONSTRAINT fk_b2b_tier_list FOREIGN KEY (price_list_id) REFERENCES mc_b2b_price_list(id) ON DELETE CASCADE,
            CONSTRAINT fk_b2b_tier_variant FOREIGN KEY (variant_id) REFERENCES mc_product_variant(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->addSql("ALTER TABLE mc_sales_order ADD b2b_company_id BIGINT UNSIGNED NULL AFTER customer_id, ADD b2b_approval_status VARCHAR(32) NULL AFTER b2b_company_id, ADD purchase_order_number VARCHAR(128) NULL AFTER b2b_approval_status, ADD payment_terms_days SMALLINT UNSIGNED NULL AFTER purchase_order_number, ADD due_at DATETIME(6) NULL AFTER payment_terms_days, ADD CONSTRAINT fk_sales_order_b2b_company FOREIGN KEY (b2b_company_id) REFERENCES mc_b2b_company(id) ON DELETE SET NULL");
        $this->addSql('CREATE INDEX idx_sales_order_b2b_approval ON mc_sales_order (b2b_company_id,b2b_approval_status,created_at)');
        $this->addSql("INSERT IGNORE INTO mc_admin_permission(code,description,risk_level) VALUES ('b2b.view','View B2B companies and price lists','low'),('b2b.manage','Manage B2B companies, pricing and approvals','medium')");
        $this->addSql("INSERT IGNORE INTO mc_admin_role_permission(role_code,permission_code) VALUES ('ROLE_VIEWER','b2b.view'),('ROLE_MANAGER','b2b.view'),('ROLE_MANAGER','b2b.manage')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM mc_admin_role_permission WHERE permission_code IN ('b2b.view','b2b.manage')");
        $this->addSql("DELETE FROM mc_admin_permission WHERE code IN ('b2b.view','b2b.manage')");
        $this->addSql('DROP INDEX idx_sales_order_b2b_approval ON mc_sales_order');
        $this->addSql('ALTER TABLE mc_sales_order DROP FOREIGN KEY fk_sales_order_b2b_company, DROP COLUMN due_at, DROP COLUMN payment_terms_days, DROP COLUMN purchase_order_number, DROP COLUMN b2b_approval_status, DROP COLUMN b2b_company_id');
        $this->addSql('DROP TABLE IF EXISTS mc_b2b_price_tier');
        $this->addSql('DROP TABLE IF EXISTS mc_b2b_price_list_company');
        $this->addSql('DROP TABLE IF EXISTS mc_b2b_price_list');
        $this->addSql('DROP TABLE IF EXISTS mc_b2b_company_member');
        $this->addSql('DROP TABLE IF EXISTS mc_b2b_company');
    }
}
