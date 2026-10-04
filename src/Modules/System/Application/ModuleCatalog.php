<?php

declare(strict_types=1);

namespace Commerce\Modules\System\Application;

/** What is installed: the built-in modules (folders of src/Modules) and the languages shipped with the platform. */
final class ModuleCatalog
{
    /**
     * Where each built-in module is managed in the admin. The admin pages live in a few controllers, not in the module
     * folders, so the map is explicit; a module without an entry has no page of its own: it is managed from the pages of other modules (for example prices and stock from the product form).
     */
    private const ADMIN_PAGE = [
        'Admin' => '/admin', 'Ai' => '/admin/system/ai', 'Analytics' => '/admin/analytics', 'Appearance' => '/admin/appearance/storefront',
        'Automation' => '/admin/automation', 'B2B' => '/admin/b2b', 'Bulk' => '/admin/catalog/products/bulk-edit', 'Catalog' => '/admin/catalog/products',
        'Checkout' => '/admin/shipments/methods', 'Content' => '/admin/content/pages', 'CustomField' => '/admin/catalog/fields', 'Customer' => '/admin/commerce/customers',
        'Developer' => '/admin/system/developer', 'DigitalProduct' => '/admin/content/downloads', 'Downloads' => '/admin/content/downloads', 'Feeds' => '/admin/commerce/feeds',
        'Forms' => '/admin/content/forms', 'Forum' => '/admin/forum', 'Fraud' => '/admin/system/fraud', 'GoogleCommerce' => '/admin/commerce/feeds',
        'Identity' => '/admin/system/access', 'ImportExport' => '/admin/commerce/import-export', 'Localization' => '/admin/system/localization',
        'Marketing' => '/admin/commerce/campaigns', 'Media' => '/admin/media', 'Migration' => '/admin/system/migration', 'Navigation' => '/admin/appearance/navigation',
        'Notification' => '/admin/commerce/notifications', 'Order' => '/admin/orders', 'Payment' => '/admin/shipments/methods',
        'Promotion' => '/admin/commerce/promotions', 'Push' => '/admin/system/push', 'Quality' => '/admin/system/quality', 'Rewards' => '/admin/rewards', 'Search' => '/admin/catalog/search', 'Seo' => '/admin/system/seo-redirects',
        'Shipping' => '/admin/shipments', 'Storefront' => '/admin/system/storefront-contacts', 'SupportChat' => '/admin/appearance/support-chat', 'System' => '/admin/system/modules',
        'Tax' => '/admin/system/tax',
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return list<array{name:string,label:string,classes:int,routes:int,has_admin:bool,admin_url:?string}> */
    public function builtIn(): array
    {
        $rows = [];
        foreach (glob($this->projectDir . '/src/Modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $classes = 0;
            $routes = 0;
            $admin = false;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $classes++;
                if (str_contains($file->getPathname(), '/Http/')) {
                    $source = (string) file_get_contents($file->getPathname());
                    $count = substr_count($source, '#[Route(');
                    $routes += $count;
                    $admin = $admin || str_contains($source, "'/admin");
                }
            }
            $name = basename($dir);
            $rows[] = [
                'name' => $name,
                'label' => trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name)),
                'classes' => $classes,
                'routes' => $routes,
                'has_admin' => $admin,
                'admin_url' => self::ADMIN_PAGE[$name] ?? null,
            ];
        }

        return $rows;
    }

    /** @return list<array{code:string,storefront:bool,admin:bool}> */
    public function languages(): array
    {
        $rows = [];
        foreach (glob($this->projectDir . '/resources/translations/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $rows[] = [
                'code' => basename($dir),
                'storefront' => is_file($dir . '/storefront.php'),
                'admin' => is_file($dir . '/admin.php'),
            ];
        }

        return $rows;
    }
}
