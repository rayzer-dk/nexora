#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    ['Composer runtime', $root . '/vendor/autoload_runtime.php', true],
    ['Environment template', $root . '/.env.example', true],
    ['Front controller', $root . '/public/index.php', true],
    ['Браузерний інсталятор', $root . '/public/setup.php', true],
    ['Інструкція встановлення', $root . '/docs/INSTALLATION.md', true],
];

$failed = false;
foreach ($checks as [$label, $path, $required]) {
    $ok = is_file($path);
    printf("%-28s %s\n", $label, $ok ? 'OK' : ($required ? 'ВІДСУТНЄ' : 'необов’язково'));
    if ($required && !$ok) {
        $failed = true;
    }
}

$viteManifest = $root . '/public/build/.vite/manifest.json';
if (is_file($viteManifest)) {
    echo "Зібрані frontend assets       OK\n";
} else {
    echo "Зібрані frontend assets       ВІДСУТНІ\n";
    $failed = true;
}

if ($failed) {
    fwrite(STDERR, "\nЦей каталог є source/development-пакетом, а не готовим one-upload production release.\n");
    exit(1);
}

echo "\nНеобхідні runtime-компоненти release присутні.\n";
