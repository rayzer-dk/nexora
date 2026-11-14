<?php

declare(strict_types=1);

// Release gate: icon names used in templates, PHP or JS must be in the Lucide or official Tabler brand set,
// and no template may draw its own inline <svg>.
$root = dirname(__DIR__);
$data = json_decode((string) file_get_contents($root . '/resources/icons/lucide.json'), true, 16, JSON_THROW_ON_ERROR);
$brands = json_decode((string) file_get_contents($root . '/resources/icons/tabler-brands.json'), true, 16, JSON_THROW_ON_ERROR)['icons'] ?? [];
$fullLucide = json_decode((string) file_get_contents($root . '/resources/icons/lucide-library.json'), true, 16, JSON_THROW_ON_ERROR)['icons'] ?? [];
$icons = array_merge($fullLucide, $data['icons'] ?? [], $brands);
$errors = [];
$socialSettings = (string) file_get_contents($root . '/src/Modules/Storefront/Infrastructure/StorefrontContactSettings.php');
if (preg_match('/public const SOCIAL = \\[([\\s\\S]*?)\\n    \\];/', $socialSettings, $networkSource)) {
    preg_match_all("/'([a-z0-9-]+)'\\s*=>\\s*\\[\\[/", $networkSource[1], $socialNames);
    foreach ($socialNames[1] as $code) {
        if (!isset($brands['brand-' . $code])) {
            $errors[] = 'Contact network "' . $code . '" has no official self-hosted Tabler brand icon.';
        }
    }
}
$manifest = json_decode((string) file_get_contents($root . '/tools/lucide-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
if (!empty($manifest['custom'])) {
    $errors[] = 'Custom SVG bodies in the Lucide manifest are forbidden; use the official Tabler brand set.';
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (preg_match('#^(vendor|node_modules|var|public|build|\.git|tests|bonus)/#', $rel) || !preg_match('/\.(twig|php|js|ts|vue)$/', $rel) || $rel === 'assets/shared/lucide-icons.js') {
        continue;
    }
    $text = (string) file_get_contents($path);
    if (preg_match_all("/(?:ui_icon|lucideIcon)\(\s*'([a-z0-9-]+)'\s*(?=,|\))/", $text, $m)) {
        foreach ($m[1] as $name) {
            if (!isset($icons[$name])) {
                $errors[] = "$rel: icon \"$name\" is not in the installed Lucide or Tabler icon sets";
            }
        }
    }
    // Data charts (marked data-chart) are drawings of numbers, not icons; every other inline svg is rejected.
    $withoutCharts = (string) preg_replace('#<svg\b[^>]*\bdata-chart\b[^>]*>.*?</svg>#s', '', $text);
    // Ignore SVG text mentioned inside Twig expressions; only actual markup is relevant.
    $markup = (string) preg_replace('/\\{[{%#][\\s\\S]*?[}%#]\\}/', '', $withoutCharts);
    if (str_ends_with($rel, '.twig') && str_contains($markup, '<svg') && !str_contains($rel, 'branding')) {
        $errors[] = "$rel: inline <svg> is not allowed; use ui_icon() with a Lucide icon";
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", array_unique($errors)) . "\n");
    exit(1);
}
echo 'Icons OK: ' . count($data['icons'] ?? []) . ' bundled Lucide + ' . count($fullLucide) . ' optional Lucide + ' . count($brands) . " Tabler brand icons.\n";
