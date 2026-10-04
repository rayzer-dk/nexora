<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$mustContain = [
    'config/packages/html_sanitizer.yaml' => ['commerce.rich_text', 'allow_safe_elements'],
    'src/Modules/Catalog/Application/ProductWriter.php' => ['HtmlSanitizerInterface', 'richTextSanitizer'],
    'src/Modules/Content/Application/InformationPageService.php' => ['HtmlSanitizerInterface', 'sanitize('],
    'src/Modules/Appearance/Builder/LayoutSchemaValidator.php' => ['strip_tags(', 'sanitizeNested('],
    'src/Modules/Marketing/Application/NewsletterCampaignService.php' => ['HtmlSanitizerInterface', 'commerce.email_html'],
];
foreach ($mustContain as $file => $needles) {
    $content = @file_get_contents($root . '/' . $file);
    if (!is_string($content)) { $errors[] = "missing {$file}"; continue; }
    foreach ($needles as $needle) if (!str_contains($content, $needle)) $errors[] = "{$file} missing {$needle}";
}

$allowedRaw = [
    'themes/default/templates/product/show.html.twig',
    'themes/default/templates/product/_info_block.html.twig',
    'themes/default/templates/product/blocks/description.html.twig',
    'themes/default/templates/blog/article.html.twig',
    'themes/default/templates/content/page.html.twig',
    'themes/default/templates/base.html.twig',
    // The shop owner's own e-mail HTML: sanitized (commerce.email_html) when the campaign is saved, never raw from the request.
    'themes/default/templates/email/campaign.html.twig',
    'themes/default/templates/email/campaign_raw.html.twig',
    // custom_html is the owner's template body, sanitized by NotificationTemplateService::cleanHtml() on save.
    'themes/default/templates/email/generic.html.twig',
    'themes/default/templates/email/order_status.html.twig',
    'themes/default/templates/email/order_created.html.twig',
    // Bundled documentation rendered by MarkdownLite, which HTML-escapes every character of the source first.
    'themes/default/templates/admin/help/doc.html.twig',
];

$baseTemplate = @file_get_contents($root . '/themes/default/templates/base.html.twig');
if (is_string($baseTemplate)) {
    if (substr_count($baseTemplate, '|raw') !== 1
        || !str_contains($baseTemplate, 'seo_head.json_ld|json_encode')
        || !str_contains($baseTemplate, "constant('JSON_HEX_TAG')")
        || !str_contains($baseTemplate, "constant('JSON_HEX_AMP')")
        || !str_contains($baseTemplate, "constant('JSON_HEX_APOS')")
        || !str_contains($baseTemplate, "constant('JSON_HEX_QUOT')")) {
        $errors[] = 'base template raw output is allowed only for HEX-escaped JSON-LD';
    }
}
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
