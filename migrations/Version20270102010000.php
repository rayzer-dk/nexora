<?php

declare(strict_types=1);

namespace Commerce\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270102010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Google Ads conversion tag, Google Customer Reviews opt-in and Merchant Center loyalty program settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mc_tracking_settings
            ADD google_ads_id VARCHAR(20) NOT NULL DEFAULT '' AFTER meta_pixel_id,
            ADD google_ads_purchase_label VARCHAR(40) NOT NULL DEFAULT '' AFTER google_ads_id,
            ADD gcr_merchant_id VARCHAR(20) NOT NULL DEFAULT '' AFTER google_ads_purchase_label,
            ADD gcr_delivery_days SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER gcr_merchant_id,
            ADD loyalty_program_label VARCHAR(60) NOT NULL DEFAULT '' AFTER gcr_delivery_days");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mc_tracking_settings DROP google_ads_id, DROP google_ads_purchase_label, DROP gcr_merchant_id, DROP gcr_delivery_days, DROP loyalty_program_label');
    }
}
