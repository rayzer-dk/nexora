#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Platform/PlatformVersion.php';


$root = dirname(__DIR__);
$errors = [];
$warnings = [];

$required = [
    'src/Core/Platform/PlatformVersion.php',
    'src/Modules/Storefront/Infrastructure/StorefrontContextResolver.php',
    'src/Modules/Admin/Http/AdminContextResolver.php',
    'src/Modules/Admin/Http/AdminContextTwigExtension.php',
    'src/Modules/Return/Application/ReturnRequestService.php',
    'src/Modules/Compare/Http/ProductCompareController.php',
    'src/Modules/Cart/Application/SavedCartService.php',
    'src/Modules/Navigation/Application/NavigationManager.php',
    'src/Core/I18n/StorefrontUiTranslator.php',
    'migrations/Version20260925060000.php',
    'migrations/Version20260925070000.php',
    'migrations/Version20260925071000.php',
    'themes/default/templates/admin/catalog/_shell.html.twig',
];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) $errors[] = 'Missing required file: ' . $file;
}

$vite = file_get_contents($root . '/vite.config.ts') ?: '';
preg_match_all("/'(assets\\/(?:admin|storefront)\\/[^']+\\.(?:css|js|ts))'/", $vite, $m);
foreach (array_unique($m[1] ?? []) as $asset) {
    if (!is_file($root . '/' . $asset)) $errors[] = 'Vite input does not exist: ' . $asset;
}

$shell = file_get_contents($root . '/themes/default/templates/admin/catalog/_shell.html.twig') ?: '';
foreach (['assets/admin/admin-runtime.css','assets/admin/admin-builder-media.css','assets/storefront/admin-runtime.js'] as $key) {
    if (!str_contains($shell, "vite_asset('" . $key . "'")) $errors[] = 'Admin shell Vite key mismatch: ' . $key;
}

$catalogPhp = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src/Modules/Admin/Http'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') $catalogPhp .= file_get_contents($f->getPathname()) ?: '';
}
if (preg_match("/currency\s*:\s*['\"]UAH['\"]/", $catalogPhp)) $errors[] = 'Admin product runtime still hard-codes UAH.';

$storefront = file_get_contents($root . '/src/Modules/Storefront/Infrastructure/StorefrontContextResolver.php') ?: '';
if (!str_contains($storefront, 'mc_store_domain')) $errors[] = 'Storefront context has no host/domain store resolution.';
if (!str_contains($storefront, 'activeCount === 1')) $warnings[] = 'Single-store compatibility fallback marker not found.';

$catalog = file_get_contents($root . '/src/Core/Module/SystemModuleCatalog.php') ?: '';
foreach (['gift_cards','loyalty','b2b','attribution','abandoned_cart','developer_tools'] as $code) {
    $needle = "\$system('" . $code . "'";
    $pos = strpos($catalog, $needle);
    if ($pos === false) { $errors[] = 'System module is not declared: ' . $code; continue; }
    $lineEnd = strpos($catalog, "
", $pos);
    $line = substr($catalog, $pos, $lineEnd === false ? null : $lineEnd - $pos);
    if (str_contains($line, ', true, ModuleMaturity::Foundation') || str_contains($line, ', true, ModuleMaturity::Planned')) {
        $errors[] = 'Incomplete system module enabled by default: ' . $code;
    }
}


$translationFiles = glob($root . '/resources/translations/*/storefront.php') ?: [];
$ukPath = $root . '/resources/translations/uk-UA/storefront.php';
$ukCatalog = is_file($ukPath) ? require $ukPath : [];
if (!is_array($ukCatalog) || $ukCatalog === []) {
    $errors[] = 'Canonical uk-UA/storefront.php catalog is missing or empty.';
} else {
    $ukKeys = array_keys($ukCatalog);
    foreach ($translationFiles as $file) {
        if ($file === $ukPath) continue;
        $data = require $file;
        if (!is_array($data)) { $errors[] = 'Invalid translation catalog: ' . $file; continue; }
        $extra = array_diff(array_keys($data), $ukKeys);
        if ($extra) $errors[] = 'Foreign storefront catalog contains keys absent from uk-UA canonical catalog: ' . $file . ' extra=' . implode(',', $extra);
    }
}
if (count($translationFiles) < 3) $warnings[] = 'Only ' . count($translationFiles) . ' storefront UI translation catalogs found.';

$twigRoot = $root . '/themes/default/templates';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($twigRoot));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
    $body = file_get_contents($f->getPathname()) ?: '';
    preg_match_all("/@storefront\\/([A-Za-z0-9_\\/.:-]+\\.html\\.twig)/", $body, $refs);
    foreach (array_unique($refs[1] ?? []) as $ref) {
        if (!is_file($twigRoot . '/' . $ref)) $errors[] = 'Broken Twig template reference in ' . substr($f->getPathname(), strlen($root)+1) . ': @storefront/' . $ref;
    }
}

$pkg = json_decode(file_get_contents($root . '/package.json') ?: '{}', true);
if (($pkg['version'] ?? '') !== \Commerce\Core\Platform\PlatformVersion::VERSION) $errors[] = 'package.json version must match PlatformVersion.';
if (($pkg['scripts']['build:production'] ?? '') !== 'vite build && node tools/check-asset-budgets.mjs && node tools/precompress-assets.mjs public/build') $errors[] = 'package build:production path is inconsistent with Vite outDir.';

if (!is_file($root . '/composer.lock')) $warnings[] = 'composer.lock missing: source package cannot be certified reproducible.';
if (!is_file($root . '/package-lock.json')) $warnings[] = 'package-lock.json missing: source package cannot be certified reproducible.';
if (!is_dir($root . '/vendor')) $warnings[] = 'vendor missing: this is not a one-upload production package.';
if (!is_dir($root . '/public/build')) $warnings[] = 'public/build missing: compiled Vite assets are not included.';

if ($errors) {
    fwrite(STDERR, "SYSTEM COMPLETENESS: FAILED\n");
    foreach ($errors as $e) fwrite(STDERR, "ERROR: $e\n");
    foreach ($warnings as $w) fwrite(STDERR, "WARN: $w\n");
    exit(1);
}

echo "SYSTEM COMPLETENESS: PASSED\n";
foreach ($warnings as $w) echo "WARN: $w\n";
exit(0);
