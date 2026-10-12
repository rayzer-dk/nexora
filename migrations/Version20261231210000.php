<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261231210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Affiliate programme: partners with a link, the clicks they bring, the orders they get credit for and the commission with its payout.';
    }

    public function up(Schema $schema): void
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->addSql('CREATE TABLE mc_affiliate (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            email VARCHAR(320) NOT NULL DEFAULT \'\',
            code VARCHAR(16) NOT NULL,
            commission_percent DECIMAL(5,2) NOT NULL DEFAULT 5.00,
            status VARCHAR(16) NOT NULL DEFAULT \'active\',
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_affiliate_code (code),
            KEY idx_affiliate_store (store_id, status),
            CONSTRAINT fk_affiliate_store FOREIGN KEY (store_id) REFERENCES mc_store(id) ON DELETE CASCADE
        )' . $t);
        $this->addSql('CREATE TABLE mc_affiliate_click (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            affiliate_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_affiliate_click (affiliate_id, created_at),
            CONSTRAINT fk_affiliate_click FOREIGN KEY (affiliate_id) REFERENCES mc_affiliate(id) ON DELETE CASCADE
        )' . $t);
        $this->addSql('CREATE TABLE mc_affiliate_order (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            affiliate_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            base_minor BIGINT UNSIGNED NOT NULL,
            commission_minor BIGINT UNSIGNED NOT NULL,
            payout_status VARCHAR(16) NOT NULL DEFAULT \'open\',
            paid_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_affiliate_order (order_id),
            KEY idx_affiliate_order (affiliate_id, payout_status),
            CONSTRAINT fk_affiliate_order_aff FOREIGN KEY (affiliate_id) REFERENCES mc_affiliate(id) ON DELETE CASCADE,
            CONSTRAINT fk_affiliate_order_order FOREIGN KEY (order_id) REFERENCES mc_sales_order(id) ON DELETE CASCADE
        )' . $t);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mc_affiliate_order');
        $this->addSql('DROP TABLE mc_affiliate_click');
        $this->addSql('DROP TABLE mc_affiliate');
    }
}
