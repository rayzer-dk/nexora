<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$warnings = [];

$routeNames = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $text = (string) file_get_contents($file->getPathname());
    if (preg_match_all("/#\\[Route\\([^\\]]*?name\\s*:\\s*'([^']+)'/s", $text, $m)) {
        foreach ($m[1] as $name) {
            if (isset($routeNames[$name])) $errors[] = "Duplicate Symfony route name: $name";
            $routeNames[$name] = true;
        }
    }
}

$templates = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes/default/templates', FilesystemIterator::SKIP_DOTS));
foreach ($templates as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
    $relative = str_replace($root . '/', '', $file->getPathname());
    $text = (string) file_get_contents($file->getPathname());

    if (preg_match_all("/(?:path|url)\\(\\s*'([^']+)'/", $text, $m)) {
        foreach ($m[1] as $name) {
            if (str_contains($name, '{{')) continue;
            if (!isset($routeNames[$name])) $errors[] = "$relative references unknown route $name";
        }
    }

    if (preg_match_all('/<form\\b[^>]*method=["\']post["\'][^>]*>(.*?)<\\/form>/is', $text, $forms)) {
        foreach ($forms[1] as $body) {
            if (!str_contains($body, 'csrf_token(') && !str_contains($body, '_token') && !str_contains($body, '_csrf_token')) {
                $errors[] = "$relative has POST form without visible CSRF token";
            }
        }
    }

    if (preg_match('/href=["\']#["\']/', $text)) {
        $errors[] = "$relative contains dead href=\"#\"";
    }

    if (preg_match_all('/<button\\b[^>]*type=["\']button["\'][^>]*>/i', $text, $buttons)) {
        foreach ($buttons[0] as $button) {
            if (!preg_match('/(?:data-[a-z0-9_-]+|aria-[a-z0-9_-]+|title=|onclick=)/i', $button)) {
                $warnings[] = "$relative has a type=button control without an interaction hook: " . preg_replace('/\\s+/', ' ', $button);
            }
        }
    }
}

$requiredResponsive = [
    'assets/storefront/storefront.css' => ['@media(min-width:768px)', '@media(min-width:1024px)'],
    'assets/admin/admin-runtime.css' => ['@media(max-width:1024px)', '@media(max-width:768px)'],
    'assets/admin/admin-builder-media.css' => ['@media(max-width:1024px)', '@media(max-width:768px)'],
];
foreach ($requiredResponsive as $file => $needles) {
    $text = is_file($root . '/' . $file) ? (string) file_get_contents($root . '/' . $file) : '';
    foreach ($needles as $needle) if (!str_contains(str_replace(' ', '', $text), str_replace(' ', '', $needle))) $errors[] = "$file missing responsive breakpoint $needle";
}

$requiredJourneyRoutes = [
    'storefront_home','storefront_catalog','storefront_seo_entity','storefront_cart','storefront_cart_add','storefront_cart_update','storefront_cart_remove',
    'storefront_checkout','storefront_checkout_place','storefront_checkout_success','customer_login','customer_register','customer_account','customer_password_forgot',
    'admin_login','admin_dashboard','admin_catalog_products','admin_catalog_categories','admin_orders','admin_appearance_storefront','admin_media_library','admin_system_store','admin_system_site',
];
foreach ($requiredJourneyRoutes as $name) if (!isset($routeNames[$name])) $errors[] = "Required journey route missing: $name";

$emailTemplates = ['generic','order_created','order_status'];
foreach ($emailTemplates as $name) if (!is_file($root . "/themes/default/templates/email/$name.html.twig")) $errors[] = "Email template missing: $name";
foreach (['src/Modules/Notification/Application/NotificationOutboxWorker.php','src/Modules/Notification/Channel/Email/EmailNotificationSender.php','src/Modules/Notification/EventSubscriber/OrderPlacedNotificationSubscriber.php'] as $file) {
    if (!is_file($root . '/' . $file)) $errors[] = "Notification chain component missing: $file";
}

if ($errors !== []) {
    fwrite(STDERR, "Full release static check FAILED\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(1);
}

echo "Full release static check: OK\n";
echo 'routes=' . count($routeNames) . "\n";
echo 'warnings=' . count(array_unique($warnings)) . "\n";
foreach (array_slice(array_unique($warnings), 0, 20) as $warning) echo "WARN: $warning\n";
