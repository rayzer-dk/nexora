#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guards the rules "no UI text in code, only in language files" and "CSP-safe templates":
 *  - every literal ui_text('key') used in templates/JS exists in the uk-UA catalog (the fallback);
 *  - the en-US catalog covers every uk-UA key, so the admin interface can switch to English completely;
 *  - every shipped storefront language covers the keys that storefront templates use;
 *  - templates contain no Cyrillic text outside ui_text() and no inline event handlers (blocked by CSP).
 */

$root = dirname(__DIR__);
$errors = [];

$load = static function (string $locale) use ($root): array {
    $all = [];
    foreach (glob($root . '/resources/translations/' . $locale . '/*.php') ?: [] as $file) {
        $data = include $file;
        if (is_array($data)) {
            $all += $data;
        }
    }
    return $all;
};

foreach (glob($root . '/resources/translations/*/*.php') ?: [] as $translationFile) {
    $source = (string) file_get_contents($translationFile);
    preg_match_all('/^\\s*([\'\"])([^\'\"]+)\\1\\s*=>/m', $source, $matches);
    $seen = [];
    foreach ($matches[2] as $key) {
        if (isset($seen[$key])) {
            $errors[] = str_replace($root . '/', '', $translationFile) . ': duplicate key ' . $key;
            continue;
        }
        $seen[$key] = true;
    }
}

$uk = $load('uk-UA');
$en = $load('en-US');
foreach (array_diff_key($uk, $en) as $key => $_) {
    $errors[] = 'en-US is missing key ' . $key;
}

$storefrontKeys = [];
$templates = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes/default/templates', FilesystemIterator::SKIP_DOTS));
foreach ($templates as $file) {
    if ($file->getExtension() !== 'twig') {
        continue;
    }
    $path = substr($file->getPathname(), strlen($root) + 1);
    $text = (string) file_get_contents($file->getPathname());
    $isStorefront = !str_contains($path, '/admin/') && !str_contains($path, '/install');
    preg_match_all("/ui_text\\(\\s*'([^']+)'/", $text, $m);
    foreach ($m[1] as $key) {
        if (str_ends_with($key, '.') || str_ends_with($key, '_')) {
            continue; // prefix of a key completed at runtime ('status.' ~ code)
        }
        if (!isset($uk[$key])) {
            $errors[] = $path . ': unknown key ' . $key;
        }
        if ($isStorefront) {
            $storefrontKeys[$key] = true;
        }
    }
    $code = preg_replace('/\{#.*?#\}/s', '', $text) ?? $text;
    $code = preg_replace('/ui_text\([^)]*\)/', '', $code) ?? $code;
    if (preg_match('/\p{Cyrillic}{2,}/u', $code, $hit) === 1) {
        $errors[] = $path . ': hardcoded Cyrillic text "' . $hit[0] . '"';
    }
    if (preg_match('/\son(?:click|change|submit|input|load|error|keyup|keydown)="/i', $code, $hit) === 1) {
        $errors[] = $path . ': inline event handler (blocked by CSP), use data-* + runtime JS';
    }
}

foreach (glob($root . '/resources/translations/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $locale = basename($dir);
    if (in_array($locale, ['uk-UA', 'en-US'], true) || !is_file($dir . '/storefront.php')) {
        continue;
    }
    $catalog = $load($locale);
    $missing = array_diff_key($storefrontKeys, $catalog);
    if ($missing !== []) {
        $errors[] = $locale . ' is missing ' . count($missing) . ' storefront keys, e.g. ' . implode(', ', array_slice(array_keys($missing), 0, 5));
    }
}

if ($errors !== []) {
    fwrite(STDERR, "i18n/template check FAILED:\n - " . implode("\n - ", array_slice($errors, 0, 80)) . "\n");
    exit(1);
}
echo 'i18n/template check: OK (' . count($uk) . " keys, " . count($storefrontKeys) . " storefront keys)\n";
