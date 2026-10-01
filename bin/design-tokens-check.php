<?php

declare(strict_types=1);

// Release gate: components use design tokens instead of raw values for type, layers, radius, elevation and breakpoints.
$root = dirname(__DIR__);
$scope = $argv[1] ?? 'all';
$files = glob($root . '/assets/{admin,storefront}{,/parts}/*.css', GLOB_BRACE);
if ($scope !== 'all') {
    $files = array_filter($files, static fn (string $f) => str_contains($f, "/assets/$scope/"));
}
$allowedWidths = [480, 640, 768, 1024, 1280, 1536];
$errors = [];
foreach ($files as $file) {
    $rel = substr($file, strlen($root) + 1);
    $css = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file)) ?? '';
    $rules = [
        '/font-size\s*:\s*+(?!var\()[^;}]*?\d/i' => 'raw font-size (use --mc-font-size-*)',
        '/z-index\s*:\s*+(?!var\(|-?[01]\b|auto)[^;}]+/i' => 'raw z-index (use --mc-z-*)',
        '/border-radius\s*:\s*+(?!var\(|0\b|50%|inherit|[^;}]*var\()[^;}]*\d/i' => 'raw border-radius (use --mc-radius-*)',
        '/box-shadow\s*:\s*+(?!var\(|none|inset|[^;}]*var\()[^;}]*rgba?\(/i' => 'raw box-shadow (use --mc-shadow-*)',
    ];
    foreach ($rules as $regex => $message) {
        if (preg_match_all($regex, $css, $m)) {
            $errors[] = sprintf('%s: %d × %s', $rel, count($m[0]), $message);
        }
    }
    if (preg_match_all('/\((?:min|max)-width\s*:\s*(\d+)px\)/', $css, $m)) {
        $bad = array_unique(array_filter($m[1], static fn (string $w) => !in_array((int) $w, $allowedWidths, true)));
        if ($bad !== []) {
            $errors[] = sprintf('%s: non-standard breakpoints %s (allowed %s)', $rel, implode(',', $bad), implode('/', $allowedWidths));
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "Design tokens OK ($scope).\n";
