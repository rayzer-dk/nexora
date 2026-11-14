<?php

declare(strict_types=1);

// Release gate: icon names used in templates, PHP or JS must be in the Lucide or official Tabler brand set,
// and no template may draw its own inline <svg>.
$root = dirname(__DIR__);
$data = json_decode((string) file_get_contents($root . '/resources/icons/lucide.json'), true, 16, JSON_THROW_ON_ERROR);
$brands = json_decode((string) file_get_contents($root . '/resources/icons/tabler-brands.json'), true, 16, JSON_THROW_ON_ERROR)['icons'] ?? [];
$icons = array_merge($data['icons'] ?? [], $brands);
$errors = [];
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
                $errors[] = "$rel: icon \"$name\" is not in the Lucide or Tabler icon sets (update the relevant icon manifest)";
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
echo 'Icons OK: ' . count($data['icons'] ?? []) . " Lucide icons + " . count($brands) . " Tabler brand icons.\n";
