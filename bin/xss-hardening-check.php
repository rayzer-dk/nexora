<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$mustContain = [
    'config/packages/html_sanitizer.yaml' => ['commerce.rich_text', 'allow_safe_elements'],
    'src/Modules/Catalog/Application/ProductWriter.php' => ['HtmlSanitizerInterface', 'richTextSanitizer'],
    'src/Modules/Admin/Http/ContentAdminPageController.php' => ['HtmlSanitizerInterface', 'sanitize('],
    'src/Modules/Appearance/Builder/LayoutSchemaValidator.php' => ['strip_tags(', 'sanitizeNested('],
];
foreach ($mustContain as $file => $needles) {
    $content = @file_get_contents($root . '/' . $file);
    if (!is_string($content)) { $errors[] = "missing {$file}"; continue; }
    foreach ($needles as $needle) if (!str_contains($content, $needle)) $errors[] = "{$file} missing {$needle}";
}

$allowedRaw = [
    'themes/default/templates/product/show.html.twig',
    'themes/default/templates/product/blocks/description.html.twig',
    'themes/default/templates/blog/article.html.twig',
    'themes/default/templates/content/page.html.twig',
];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
    $rel = str_replace('\\','/', substr($file->getPathname(), strlen($root) + 1));
    $c = file_get_contents($file->getPathname());
    if (is_string($c) && str_contains($c, '|raw') && !in_array($rel, $allowedRaw, true)) $errors[] = "unreviewed Twig raw output: {$rel}";
}

foreach (['assets','public/assets'] as $dir) {
    if (!is_dir($root.'/'.$dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['js','ts'], true)) continue;
        $c = file_get_contents($file->getPathname());
        if (!is_string($c)) continue;
        if (preg_match('/\b(document\.write|insertAdjacentHTML)\s*\(/', $c)) $errors[] = 'unsafe DOM HTML sink: '.$file->getPathname();
        // Dynamic HTML is permitted only in the reviewed Builder renderer, where every interpolated value is passed through esc().
        if (preg_match_all('/\.innerHTML\s*=\s*`([^`]*\$\{[^`]*)`/s', $c, $m)) {
            foreach ($m[1] as $expr) {
                if (!str_contains($file->getPathname(), 'builder.js') || !str_contains($expr, 'esc(')) {
                    $errors[] = 'dynamic innerHTML requires safe DOM APIs or explicit escaping: '.$file->getPathname();
                    break;
                }
            }
        }
    }
}

$jsonLd = @file_get_contents($root . '/src/Modules/Storefront/Http/StorefrontCatalogController.php');
if (is_string($jsonLd) && str_contains($jsonLd, 'structured_data_json') && !str_contains($jsonLd, 'JSON_HEX_TAG')) $errors[] = 'structured JSON must use JSON_HEX_TAG';

if ($errors !== []) {
    fwrite(STDERR, "XSS hardening check failed:\n - ".implode("\n - ", $errors)."\n");
    exit(1);
}
echo "XSS hardening check passed.\n";
