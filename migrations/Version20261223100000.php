<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261223100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer groups with a percentage discount shown in prices and applied in the cart.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE mc_customer_group (
            code VARCHAR(64) NOT NULL,
            name VARCHAR(190) NOT NULL,
            discount_bps INT NOT NULL DEFAULT 0,
            skip_sale_items TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addSql("INSERT INTO mc_customer_group (code,name,discount_bps,sort_order,created_at) VALUES ('default','Default',0,0,UTC_TIMESTAMP(6)),('vip','VIP',0,10,UTC_TIMESTAMP(6)),('wholesale','Wholesale',0,20,UTC_TIMESTAMP(6))");
        $this->addSql("INSERT IGNORE INTO mc_customer_group (code,name,discount_bps,sort_order,created_at) SELECT DISTINCT LOWER(customer_group_code),LOWER(customer_group_code),0,100,UTC_TIMESTAMP(6) FROM mc_customer WHERE customer_group_code IS NOT NULL AND customer_group_code<>''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_customer_group');
    }
}
