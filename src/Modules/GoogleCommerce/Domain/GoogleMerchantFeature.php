<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Domain;

enum GoogleMerchantFeature: string
{
    case Accounts = 'accounts';
    case Products = 'products';
    case DataSources = 'data_sources';
    case Inventory = 'inventory';
    case Promotions = 'promotions';
    case Reports = 'reports';
    case Notifications = 'notifications';
    case IssueResolution = 'issue_resolution';
    case Reviews = 'reviews';
    case OrderTracking = 'order_tracking';
    case Ucp = 'ucp';
    case AgenticMcp = 'merchant_mcp';
}
