<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Platform/PlatformVersion.php';

$root = dirname(__DIR__);
$failures = [];
$checks = [
    'public/setup.php' => ['install_demo', "it('installer.demo_checkbox')"],
    'src/Modules/Demo/Application/DemoSeeder.php' => ['DEMO10', "'coming_soon'", "'backorder'", "'notify'", 'demoPresentation(', 'seedForumDemo'],
    'src/Modules/Demo/Application/DemoShowcaseQuery.php' => ['announcement', 'promos', 'counts', 'brands', 'coupon'],
    'src/Modules/Storefront/HomeController.php' => ['DemoShowcaseQuery', 'demo_showcase'],
    'themes/default/templates/home.html.twig' => ['demo_showcase', 'demo-brand-list', 'demo-contact-cta'],
    'themes/default/templates/home/blocks/hero.html.twig' => ['demo-hero-card'],
    'themes/default/templates/home/blocks/products.html.twig' => ['demo-product-grid'],
    'themes/default/templates/components/product_card.html.twig' => ['/cart/add', "csrf_token('cart_mutation')", 'purchase_allowed', 'data-card-add-to-cart'],
    'assets/storefront/storefront-runtime.js' => ['initProductCardCartActions', 'data-card-add-to-cart'],
    'assets/storefront/cart-drawer.js' => ['data-cart-count'],
    'src/Modules/Appearance/Infrastructure/StorefrontPresentationSettings.php' => ["'theme'", "'primary'", "'accent'", "'success'"],
    'themes/default/templates/admin/appearance/storefront.html.twig' => ['theme_primary', 'theme_accent', 'theme_success'],
    'assets/storefront/storefront.css' => ['.demo-promo-card', '.demo-mini-banner', '@media (min-width: 768px)'],
];

foreach ($checks as $file => $needles) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $failures[] = "missing {$file}";
        continue;
    }
    $content = (string) file_get_contents($path);
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            $failures[] = "{$file}: missing {$needle}";
        }
    }
}

$release = json_decode((string) file_get_contents($root . '/resources/platform/release.json'), true);
if (($release['version'] ?? null) !== \Commerce\Core\Platform\PlatformVersion::VERSION) {
    $failures[] = 'release version does not match PlatformVersion';
}
if ((string) ($release['database_schema'] ?? '') !== (string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA) {
    $failures[] = 'database schema changed unexpectedly';
}

if ($failures !== []) {
    fwrite(STDERR, "Demo showcase check FAILED\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Demo showcase check passed: installable premium demo, functional commerce CTAs, responsive visual system and configurable storefront colors are present.\n";
