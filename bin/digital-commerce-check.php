<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Platform/PlatformVersion.php';

$root = dirname(__DIR__);
$fail = [];
$mustContain = [
    'migrations/Version20260925080000.php' => ['mc_product_digital_asset','mc_digital_entitlement','max_downloads','expires_at'],
    'src/Modules/DigitalProduct/Application/ProductDigitalAssetService.php' => ['/var/storage/','MAX_BYTES','DENIED_MIME_FRAGMENTS'],
    'src/Modules/DigitalProduct/Application/DigitalDownloadService.php' => ['download_count','payment_status','max_downloads'],
    'src/Modules/Order/Application/CheckoutOrderService.php' => ['mc_digital_entitlement','checkoutorderservice.dlia_tsyfrovykh_tovariv_uviidit_abo_stvorit_oblikovy','mc_product_digital_asset'],
    'src/Modules/Payment/Application/PaymentLifecycleService.php' => ['activateDigitalEntitlements','status=\'revoked\''],
    'src/Modules/Payment/Application/PaymentRefundService.php' => ['mc_digital_entitlement','status=\'revoked\''],
    'src/Core/Recovery/RecoverySnapshotService.php' => ['var/storage/digital'],
    'src/Modules/Customer/Http/CustomerAccountController.php' => ['customer_digital_download','BinaryFileResponse','X-Content-Type-Options'],
    'themes/default/templates/admin/catalog/product_form.html.twig' => ['digital-files','admin_catalog_product_digital_upload'],
    'themes/default/templates/account/order.html.twig' => ['customer_digital_download',"ui_text('digital_files')"],
];
foreach ($mustContain as $file => $needles) {
    $path = $root . '/' . $file;
    $text = @file_get_contents($path);
    if (!is_string($text)) { $fail[] = 'missing ' . $file; continue; }
    foreach ($needles as $needle) if (!str_contains($text, $needle)) $fail[] = $file . ' missing ' . $needle;
}
$release = json_decode((string) @file_get_contents($root . '/resources/platform/release.json'), true);
if (($release['version'] ?? null) !== \Commerce\Core\Platform\PlatformVersion::VERSION) $fail[] = 'release version';
if ((string) ($release['database_schema'] ?? '') !== (string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA) $fail[] = 'database schema';
if (!in_array('digital-product-download-entitlements', (array) ($release['capabilities'] ?? []), true)) $fail[] = 'capability';
if ($fail !== []) {
    fwrite(STDERR, "Digital commerce check FAILED\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}
echo "Digital commerce check: OK\n";
