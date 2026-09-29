<?php

declare(strict_types=1);

$locale = $argv[1] ?? 'uk-UA';
if (!in_array($locale, ['uk-UA', 'ru-RU', 'en-US'], true)) {
    fwrite(STDERR, "Unsupported installer locale.\n");
    exit(2);
}

$root = dirname(__DIR__);
$reference = require $root . '/resources/translations/uk-UA/installer.php';
$catalog = require $root . '/resources/translations/' . $locale . '/installer.php';
if (array_keys($reference) !== array_keys($catalog)) {
    fwrite(STDERR, "Installer translation keys differ for {$locale}.\n");
    exit(1);
}

$_GET['lang'] = $locale;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['DOCUMENT_ROOT'] = $root . '/public';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/setup.php';
$_SERVER['REQUEST_URI'] = '/setup.php?lang=' . $locale;

ob_start();
try {
    require $root . '/public/setup.php';
    $html = (string) ob_get_clean();
} catch (Throwable $error) {
    ob_end_clean();
    fwrite(STDERR, 'Installer rendering failed: ' . $error->getMessage() . "\n");
    exit(1);
}

foreach (['<form method="post"', 'name="db_host"', 'name="admin_email"', '</body></html>'] as $fragment) {
    if (!str_contains($html, $fragment)) {
        fwrite(STDERR, 'Installer output is incomplete: missing ' . $fragment . "\n");
        exit(1);
    }
}

if (preg_match('/\binstaller\.[a-z_]+\b/', $html) === 1) {
    fwrite(STDERR, "Installer output contains untranslated keys for {$locale}.\n");
    exit(1);
}

echo "Installer rendered completely for {$locale}.\n";
