<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261206100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Checkout leads: contact details typed into an unfinished checkout, so abandoned carts keep a name, phone and e-mail.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS mc_checkout_lead (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            store_id BIGINT UNSIGNED NOT NULL,
            cart_id BIGINT UNSIGNED NOT NULL,
            customer_name VARCHAR(190) DEFAULT NULL,
            email VARCHAR(320) DEFAULT NULL,
            phone VARCHAR(32) DEFAULT NULL,
            locale VARCHAR(16) DEFAULT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_checkout_lead_cart (cart_id),
            KEY idx_checkout_lead_store (store_id, updated_at),
            CONSTRAINT fk_checkout_lead_cart FOREIGN KEY (cart_id) REFERENCES mc_cart (id) ON DELETE CASCADE,
            CONSTRAINT fk_checkout_lead_store FOREIGN KEY (store_id) REFERENCES mc_store (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE mc_marketing_automation ADD include_leads TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_marketing_automation DROP include_leads');
        $this->addSql('DROP TABLE IF EXISTS mc_checkout_lead');
    }
}
