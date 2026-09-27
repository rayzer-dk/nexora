<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260916150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial indexed commerce schema for catalog, inventory, checkout, orders, notifications and operations.';
    }

    public function up(Schema $schema): void
    {
        if (!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform)) {
            throw new RuntimeException('Nexora Commerce supports MySQL/MariaDB for this migration.');
        }

        $path = dirname(__DIR__) . '/resources/database/mysql/001_core_schema.sql';
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->addSql($statement);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'mc_update_history',
            'mc_component_state',
            'mc_security_event',
            'mc_audit_log',
            'mc_webhook_delivery',
            'mc_notification_outbox',
            'mc_outbox_event',
            'mc_seo_redirect',
            'mc_product_media',
            'mc_media_asset',
            'mc_payment',
            'mc_fulfillment',
            'mc_sales_order_item',
            'mc_sales_order',
            'mc_cart_item',
            'mc_cart',
            'mc_inventory',
            'mc_inventory_location',
            'mc_price',
            'mc_product_attribute_value',
            'mc_attribute_translation',
            'mc_attribute_definition',
            'mc_product_variant',
            'mc_product_category',
            'mc_category_translation',
            'mc_category',
            'mc_product_translation',
            'mc_store_product',
            'mc_product',
            'mc_customer_identity',
            'mc_customer',
            'mc_store',
        ] as $table) {
            $this->addSql('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
