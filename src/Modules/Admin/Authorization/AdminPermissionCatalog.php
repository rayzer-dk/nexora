<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Authorization;

final class AdminPermissionCatalog
{
    public const DASHBOARD_VIEW = 'dashboard.view';
    public const ANALYTICS_VIEW = 'analytics.view';
    public const B2B_VIEW = 'b2b.view';
    public const B2B_MANAGE = 'b2b.manage';
    public const REWARDS_VIEW = 'rewards.view';
    public const REWARDS_MANAGE = 'rewards.manage';
    public const CATALOG_VIEW = 'catalog.view';
    public const CATALOG_MANAGE = 'catalog.manage';
    public const CATALOG_DELETE = 'catalog.delete';
    public const CATALOG_EXPORT = 'catalog.export';
    public const CATALOG_BULK = 'catalog.bulk';
    public const ORDERS_VIEW = 'orders.view';
    public const ORDERS_MANAGE = 'orders.manage';
    public const ORDERS_REFUND = 'orders.refund';
    public const ORDERS_EXPORT = 'orders.export';
    public const CUSTOMERS_VIEW = 'customers.view';
    public const CUSTOMERS_MANAGE = 'customers.manage';
    public const CUSTOMERS_EXPORT = 'customers.export';
    public const CONTENT_VIEW = 'content.view';
    public const CONTENT_MANAGE = 'content.manage';
    public const CONTENT_DELETE = 'content.delete';
    public const APPEARANCE_VIEW = 'appearance.view';
    public const APPEARANCE_MANAGE = 'appearance.manage';
    public const MEDIA_VIEW = 'media.view';
    public const MEDIA_MANAGE = 'media.manage';
    public const MEDIA_DELETE = 'media.delete';
    public const FORUM_VIEW = 'forum.view';
    public const FORUM_MANAGE = 'forum.manage';
    public const SEARCH_VIEW = 'search.view';
    public const SEARCH_MANAGE = 'search.manage';
    public const MARKETING_VIEW = 'marketing.view';
    public const MARKETING_MANAGE = 'marketing.manage';
    public const FEEDS_VIEW = 'feeds.view';
    public const FEEDS_MANAGE = 'feeds.manage';
    public const NOTIFICATIONS_VIEW = 'notifications.view';
    public const NOTIFICATIONS_MANAGE = 'notifications.manage';
    public const EXTENSIONS_MANAGE = 'extensions.manage';
    public const SYSTEM_SETTINGS = 'system.settings';
    public const SYSTEM_RECOVERY = 'system.recovery';
    public const SYSTEM_UPDATE = 'system.update';
    public const SYSTEM_CRON_VIEW = 'system.cron.view';
    public const SYSTEM_CRON_MANAGE = 'system.cron.manage';
    public const INTEGRATIONS_MANAGE = 'integrations.manage';
    public const ADMIN_USERS_MANAGE = 'admin_users.manage';
    public const SYSTEM_AUDIT_VIEW = 'system.audit.view';
    public const DEVELOPER_TOOLS_VIEW = 'developer_tools.view';

    private function __construct() {}
}
