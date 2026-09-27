<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog scale indexes for 50k+ product storefront and admin workloads.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_product_translation_store_locale_product ON mc_product_translation (store_id, locale, product_id)');
        $this->addSql('CREATE INDEX idx_variant_product_status_sort ON mc_product_variant (product_id, status, sort_order, id)');
        $this->addSql('CREATE INDEX idx_review_product_status_rating ON mc_product_review (product_id, status, rating)');
        $this->addSql('CREATE INDEX idx_price_store_market_currency_variant ON mc_price (store_id, market_id, currency, customer_group, variant_id, min_quantity, priority, id)');
        $this->addSql('CREATE INDEX idx_attribute_product_variant_locale ON mc_product_attribute_value (product_id, variant_id, locale, attribute_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_attribute_product_variant_locale ON mc_product_attribute_value');
        $this->addSql('DROP INDEX idx_price_store_market_currency_variant ON mc_price');
        $this->addSql('DROP INDEX idx_review_product_status_rating ON mc_product_review');
        $this->addSql('DROP INDEX idx_variant_product_status_sort ON mc_product_variant');
        $this->addSql('DROP INDEX idx_product_translation_store_locale_product ON mc_product_translation');
    }
}
