<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Application;

use Commerce\Modules\GoogleCommerce\Domain\GoogleMerchantFeature;

final class GoogleMerchantFeatureMatrix
{
    /** @return array<string, array{stable: bool, enabled_by_default: bool, note: string}> */
    public function all(): array
    {
        return [
            GoogleMerchantFeature::Accounts->value => ['stable' => true, 'enabled_by_default' => true, 'note' => 'Merchant account and service configuration.'],
            GoogleMerchantFeature::Products->value => ['stable' => true, 'enabled_by_default' => true, 'note' => 'Canonical product synchronization.'],
            GoogleMerchantFeature::DataSources->value => ['stable' => true, 'enabled_by_default' => true, 'note' => 'Merchant data source management.'],
            GoogleMerchantFeature::Inventory->value => ['stable' => true, 'enabled_by_default' => true, 'note' => 'Local and regional inventory synchronization.'],
            GoogleMerchantFeature::Promotions->value => ['stable' => true, 'enabled_by_default' => true, 'note' => 'Merchant Center promotions.'],
            GoogleMerchantFeature::Reports->value => ['stable' => true, 'enabled_by_default' => true, 'note' => 'Performance and product reports.'],
            GoogleMerchantFeature::Notifications->value => ['stable' => true, 'enabled_by_default' => true, 'note' => 'Product status and program notifications.'],
            GoogleMerchantFeature::IssueResolution->value => ['stable' => true, 'enabled_by_default' => true, 'note' => 'Surface disapprovals and actionable diagnostics in admin.'],
            GoogleMerchantFeature::Reviews->value => ['stable' => false, 'enabled_by_default' => false, 'note' => 'Enable only when the account/program is eligible.'],
            GoogleMerchantFeature::OrderTracking->value => ['stable' => true, 'enabled_by_default' => false, 'note' => 'Enable when the Merchant Center program uses order tracking.'],
            GoogleMerchantFeature::Ucp->value => ['stable' => false, 'enabled_by_default' => false, 'note' => 'Google UCP is evolving and requires merchant eligibility/approval.'],
            GoogleMerchantFeature::AgenticMcp->value => ['stable' => false, 'enabled_by_default' => false, 'note' => 'Merchant API MCP is experimental and must not be a reliability dependency.'],
        ];
    }
}
