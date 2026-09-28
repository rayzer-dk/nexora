#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$strict = in_array('--strict', $argv, true);
$cyr = '/[А-Яа-яІіЇїЄєҐґ]/u';

$groups = [
    'storefront_twig' => [],
    'admin_twig' => [],
    'runtime_js' => [],
    'runtime_php_cyrillic' => [],
    'runtime_exception_literals' => [],
    'config_ui' => [],
];

$scanFiles = static function (string $dir, array $extensions): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) continue;
        $out[] = $file->getPathname();
    }
    return $out;
};

foreach ($scanFiles($root . '/themes/default/templates', ['twig']) as $path) {
    $text = (string) file_get_contents($path);
    if (!preg_match($cyr, $text)) continue;
    $relative = str_replace($root . '/', '', $path);
    if (str_contains($relative, '/templates/admin/')) $groups['admin_twig'][] = $relative;
    else $groups['storefront_twig'][] = $relative;
}

foreach (array_merge($scanFiles($root . '/public/assets', ['js','mjs']), $scanFiles($root . '/assets', ['js','mjs'])) as $path) {
    $text = (string) file_get_contents($path);
    if (preg_match($cyr, $text)) $groups['runtime_js'][] = str_replace($root . '/', '', $path);
}

foreach ($scanFiles($root . '/src', ['php']) as $path) {
    $text = (string) file_get_contents($path);
    $relative = str_replace($root . '/', '', $path);

    // Demo content and transliteration tables are data, not interface copy.
    $isDataException = str_contains($path, '/Modules/Demo/')
        || str_ends_with($path, '/Core/Install/InstallationSeeder.php')
        || str_ends_with($path, '/Modules/Seo/Application/UkrainianTransliterator.php');
    if (!$isDataException && preg_match($cyr, $text)) {
        $groups['runtime_php_cyrillic'][] = $relative;
    }

    // Detect human-readable string literals anywhere inside a throw-new
    // expression, including sprintf()/concatenation cases. Translation keys
    // inside CanonicalUiText::get(...) are removed before inspection.
    if (preg_match_all('/\bthrow\s+new\b.*?;/s', $text, $throws)) {
        foreach ($throws[0] as $throwExpression) {
            $inspect = preg_replace(
                "/CanonicalUiText::get\(\s*'[^']+'(?:\s*,\s*\[[^\]]*\])?\s*\)/s",
                '',
                $throwExpression,
            ) ?? $throwExpression;
            if (!preg_match_all('/([\'\"])((?:\\.|(?!\1).)*)\1/s', $inspect, $literals, PREG_SET_ORDER)) continue;
            foreach ($literals as $literalMatch) {
                $literal = trim((string)($literalMatch[2] ?? ''));
                if ($literal === '') continue;
                // Technical scalar tokens/codes are not user-facing prose.
                if (!str_contains($literal, ' ') && preg_match('/^[A-Za-z0-9_.:\/\/-]{1,64}$/', $literal)) continue;
                if (strlen($literal) >= 3 && $literal[0] === '/' && strrpos($literal, '/') !== 0) continue;
                if (!preg_match('/[A-Za-zА-Яа-яІіЇїЄєҐґ]/u', $literal)) continue;
                $groups['runtime_exception_literals'][] = $relative . ' :: ' . substr($literal, 0, 120);
            }
        }
    }
}

foreach ([$root . '/config/product_editor/schema.json'] as $path) {
    if (is_file($path) && preg_match($cyr, (string) file_get_contents($path))) $groups['config_ui'][] = str_replace($root . '/', '', $path);
}

foreach ($groups as &$files) { $files = array_values(array_unique($files)); sort($files); }
unset($files);

$blockers = count($groups['storefront_twig']) + count($groups['runtime_js']) + count($groups['runtime_exception_literals']);
$debt = count($groups['admin_twig']) + count($groups['runtime_php_cyrillic']) + count($groups['config_ui']);

echo "Runtime i18n hardcode audit\n";
foreach ($groups as $name => $files) echo $name . '=' . count($files) . "\n";
echo 'blockers=' . $blockers . "\n";
echo 'remaining_localization_debt_files=' . $debt . "\n";

if ($groups['runtime_exception_literals']) {
    echo "Hardcoded exception literals:\n";
    foreach ($groups['runtime_exception_literals'] as $item) echo '- ' . $item . "\n";
}
if ($debt > 0) {
    echo "Remaining localization debt files:\n";
    foreach (['admin_twig','runtime_php_cyrillic','config_ui'] as $name) {
        foreach ($groups[$name] as $file) echo '- [' . $name . '] ' . $file . "\n";
    }
}

if ($blockers > 0 || ($strict && $debt > 0)) exit(1);
