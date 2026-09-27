<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$used = [];
$installerUsed = [];
$emergencyUsed = [];

$load = static function (string $path) use (&$errors): array {
    if (!is_file($path)) {
        $errors[] = 'Missing localization catalog: ' . $path;
        return [];
    }
    $data = require $path;
    if (!is_array($data)) {
        $errors[] = 'Invalid localization catalog: ' . $path;
        return [];
    }
    return array_map('strval', $data);
};

require_once $root . '/src/Core/I18n/TranslationCatalogLoader.php';
$translationLoader = new \Commerce\Core\I18n\TranslationCatalogLoader($root);
$canonical = $translationLoader->load('uk-UA');
$installer = $load($root . '/resources/translations/uk-UA/installer.php');
$emergency = $load($root . '/resources/translations/uk-UA/emergency.php');

if ($canonical === []) {
    $errors[] = 'Canonical uk-UA catalog is empty.';
}

$scanFiles = static function (string $base, array $extensions): array {
    $result = [];
    if (!is_dir($base)) return $result;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (in_array($ext, $extensions, true)) $result[] = $file->getPathname();
    }
    return $result;
};

$runtimeFiles = array_merge(
    $scanFiles($root . '/src', ['php']),
    $scanFiles($root . '/assets', ['js','ts','vue']),
    $scanFiles($root . '/themes', ['twig']),
    $scanFiles($root . '/public', ['php','js']),
    $scanFiles($root . '/bootstrap', ['php']),
);
$runtimeFiles = array_values(array_filter($runtimeFiles, static fn (string $path): bool => !str_contains(str_replace('\\', '/', $path), '/public/build/')));

$allowedCyrillic = [
    realpath($root . '/src/Modules/Seo/Application/UkrainianTransliterator.php') ?: '',
];

foreach ($runtimeFiles as $path) {
    $real = realpath($path) ?: $path;
    $text = (string) file_get_contents($path);
    if (preg_match('/[А-Яа-яІіЇїЄєҐґ]/u', $text) && !in_array($real, $allowedCyrillic, true)) {
        $errors[] = 'Hardcoded Cyrillic runtime text: ' . str_replace($root . '/', '', $path);
    }

    if (preg_match_all("/ui_text\\(\\s*['\"]([^'\"]+)['\"]/", $text, $m)) {
        foreach ($m[1] as $key) $used[$key] = true;
    }
    if (preg_match_all("/(?:^|[^A-Za-z0-9_])t\\(\\s*['\"]([^'\"]+)['\"]/m", $text, $m)) {
        foreach ($m[1] as $key) $used[$key] = true;
    }
    if (preg_match_all("/CanonicalUiText::get\\(\\s*['\"]([^'\"]+)['\"]/", $text, $m)) {
        foreach ($m[1] as $key) $used[$key] = true;
    }
}

$setup = (string) @file_get_contents($root . '/public/setup.php');
if (preg_match_all("/\\bit\\(\\s*['\"]([^'\"]+)['\"]/", $setup, $m)) {
    foreach ($m[1] as $key) $installerUsed[$key] = true;
}
$emergencySource = (string) @file_get_contents($root . '/bootstrap/emergency.php');
if (preg_match_all('/\$emergencyText\(\s*[\'"]([^\'"]+)[\'"]/', $emergencySource, $m)) {
    foreach ($m[1] as $key) $emergencyUsed[$key] = true;
}

foreach (array_keys($used) as $key) {
    if (!array_key_exists($key, $canonical) || trim($canonical[$key]) === '') {
        $errors[] = 'Missing/empty uk-UA UI key: ' . $key;
    }
}
foreach (array_keys($installerUsed) as $key) {
    if (!array_key_exists($key, $installer) || trim($installer[$key]) === '') $errors[] = 'Missing/empty installer uk-UA key: ' . $key;
}
foreach (array_keys($emergencyUsed) as $key) {
    if (!array_key_exists($key, $emergency) || trim($emergency[$key]) === '') $errors[] = 'Missing/empty emergency uk-UA key: ' . $key;
}

foreach (glob($root . '/resources/translations/*.php') ?: [] as $path) {
    $errors[] = 'Legacy flat Core translation catalog is forbidden: ' . basename($path);
}
$ukDomainFiles = glob($root . '/resources/translations/uk-UA/*.php') ?: [];
if (count($ukDomainFiles) < 6) {
    $errors[] = 'uk-UA Core localization must be split into domain files inside resources/translations/uk-UA/.';
}

$translator = (string) @file_get_contents($root . '/src/Core/I18n/StorefrontUiTranslator.php');
$loader = (string) @file_get_contents($root . '/src/Core/I18n/TranslationCatalogLoader.php');
if (!str_contains($translator, "catalog('uk-UA')") || !str_contains($translator, 'TranslationCatalogLoader')) {
    $errors[] = 'StorefrontUiTranslator must use uk-UA fallback through TranslationCatalogLoader.';
}
if (!str_contains($loader, "resources/translations") || !str_contains($loader, "extensions") || !str_contains($loader, "extension.")) {
    $errors[] = 'TranslationCatalogLoader must merge domain catalogs and namespaced extension catalogs.';
}
$twigExtension = (string) @file_get_contents($root . '/src/Core/I18n/StorefrontUiTwigExtension.php');
if (str_contains($twigExtension, "??'en-US'")) $errors[] = 'Twig UI fallback must not default to en-US.';

$foreignCounts = [];
foreach (['en-US','de-DE','da-DK','ru-RU','pl-PL'] as $locale) {
    $path = $root . '/resources/translations/' . $locale . '/storefront.php';
    $data = $load($path);
    $foreignCounts[$locale] = count($data);
}

if ($errors !== []) {
    fwrite(STDERR, "Localization contract FAILED\n- " . implode("\n- ", array_values(array_unique($errors))) . "\n");
    exit(1);
}

echo "Localization contract: OK\n";
echo "canonical_locale=uk-UA\n";
echo 'canonical_keys=' . count($canonical) . "\n";
echo 'used_ui_keys=' . count($used) . "\n";
echo 'installer_keys=' . count($installer) . "\n";
echo 'emergency_keys=' . count($emergency) . "\n";
echo 'foreign_storefront_counts=' . json_encode($foreignCounts, JSON_UNESCAPED_SLASHES) . "\n";
echo "hardcoded_runtime_cyrillic=0 (except UkrainianTransliterator algorithm table)\n";
