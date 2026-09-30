<?php

declare(strict_types=1);

// Release gate: every icon name used in templates, PHP or JS must be a real Lucide icon in resources/icons/lucide.json,
// and no template may draw its own inline <svg>.
$root = dirname(__DIR__);
$data = json_decode((string) file_get_contents($root . '/resources/icons/lucide.json'), true, 16, JSON_THROW_ON_ERROR);
$icons = $data['icons'] ?? [];
$errors = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    $rel = substr($path, strlen($root) + 1);
    if (preg_match('#^(vendor|node_modules|var|public|\.git|tests|bonus)/#', $rel) || !preg_match('/\.(twig|php|js|ts|vue)$/', $rel) || $rel === 'assets/shared/lucide-icons.js') {
        continue;
    }
    $text = (string) file_get_contents($path);
    if (preg_match_all("/(?:ui_icon|lucideIcon)\(\s*'([a-z0-9-]+)'/", $text, $m)) {
        foreach ($m[1] as $name) {
            if (!isset($icons[$name])) {
                $errors[] = "$rel: icon \"$name\" is not in resources/icons/lucide.json (add it to tools/lucide-manifest.json and run node tools/build-lucide-icons.mjs)";
            }
        }
    }
    // Data charts (marked data-chart) are drawings of numbers, not icons; every other inline svg is rejected.
    $withoutCharts = (string) preg_replace('#<svg\b[^>]*\bdata-chart\b[^>]*>.*?</svg>#s', '', $text);
    if (str_ends_with($rel, '.twig') && str_contains($withoutCharts, '<svg') && !str_contains($rel, 'branding')) {
        $errors[] = "$rel: inline <svg> is not allowed; use ui_icon() with a Lucide icon";
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", array_unique($errors)) . "\n");
    exit(1);
}
echo 'Icons OK: ' . count($icons) . " Lucide icons, Lucide {$data['lucide_version']}.\n";
