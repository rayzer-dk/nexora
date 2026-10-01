<?php

declare(strict_types=1);

// Release gate: every CSS class used in a template must have a rule in the shipped stylesheets.
// Usage: php bin/css-coverage-check.php [admin|storefront]   (default: both)
$root = dirname(__DIR__);
$scope = $argv[1] ?? 'all';
$css = '';
foreach (glob($root . '/assets/{admin,storefront,shared}{,/parts}/*.css', GLOB_BRACE) as $file) {
    $css .= file_get_contents($file);
}
preg_match_all('/\.([a-zA-Z_][\w-]*)/', $css, $m);
$defined = array_fill_keys($m[1], true);
// Behaviour/state hooks that are styled through attribute selectors or toggled by scripts only.
$hooks = array_fill_keys(['visually-hidden', 'sr-only'], true);
$base = $root . '/themes/default/templates';
$dirs = ['admin' => [$base . '/admin'], 'storefront' => array_filter(glob($base . '/*', GLOB_ONLYDIR), static fn ($d) => basename($d) !== 'admin')];
$targets = $scope === 'all' ? array_merge(...array_values($dirs)) : ($dirs[$scope] ?? []);
$missing = [];
foreach ($targets as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!str_ends_with($file->getFilename(), '.twig')) {
            continue;
        }
        $text = (string) file_get_contents($file->getPathname());
        if (str_contains($file->getPathname(), '/order_document/')) {
            continue; // self-contained print/PDF documents carry their own inline stylesheet
        }
        $text = preg_replace('/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/s', ' ', $text) ?? '';
        if (!preg_match_all('/\bclass="([^"]*)"/', $text, $classes)) {
            continue;
        }
        foreach ($classes[1] as $value) {
            foreach (preg_split('/\s+/', trim($value)) ?: [] as $class) {
                if ($class !== '' && preg_match('/^[a-zA-Z_][\w-]*$/', $class) && !isset($defined[$class]) && !isset($hooks[$class]) && !str_ends_with($class, '-') && !str_ends_with($class, '--')) {
                    $missing[$class][substr($file->getPathname(), strlen($base) + 1)] = true;
                }
            }
        }
    }
}
if ($missing !== []) {
    ksort($missing);
    foreach ($missing as $class => $files) {
        fwrite(STDERR, sprintf("no CSS for .%s (%s)\n", $class, implode(', ', array_slice(array_keys($files), 0, 3))));
    }
    fwrite(STDERR, sprintf("%d classes without styles\n", count($missing)));
    exit(1);
}
echo "CSS coverage OK ($scope).\n";
