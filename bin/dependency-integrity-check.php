#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$composerJsonPath = $root . '/composer.json';
$composerLockPath = $root . '/composer.lock';
$packageJsonPath = $root . '/package.json';
$packageLockPath = $root . '/package-lock.json';
$vendorRuntime = $root . '/vendor/autoload_runtime.php';
$viteManifestPath = $root . '/public/build/.vite/manifest.json';

foreach ([$composerJsonPath, $packageJsonPath] as $path) {
    if (!is_file($path)) {
        $errors[] = 'Missing manifest: ' . basename($path);
    }
}

$decode = static function (string $path) use (&$errors): array {
    if (!is_file($path)) return [];
    try {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        $errors[] = basename($path) . ' is not valid JSON: ' . $e->getMessage();
        return [];
    }
};

$composerJson = $decode($composerJsonPath);
if (!is_file($composerLockPath)) {
    $errors[] = 'composer.lock is missing';
} else {
    $composerLock = $decode($composerLockPath);
    if (trim((string)($composerLock['content-hash'] ?? '')) === '') {
        $errors[] = 'composer.lock has no content-hash';
    }
    if (!isset($composerLock['packages']) || !is_array($composerLock['packages'])) {
        $errors[] = 'composer.lock packages section is missing';
    }
}

$packageJson = $decode($packageJsonPath);
if (!is_file($packageLockPath)) {
    $errors[] = 'package-lock.json is missing';
} else {
    $packageLock = $decode($packageLockPath);
    if ((int)($packageLock['lockfileVersion'] ?? 0) < 3) {
        $errors[] = 'package-lock.json must use lockfileVersion >= 3';
    }
    $rootPackage = $packageLock['packages'][''] ?? null;
    if (!is_array($rootPackage)) {
        $errors[] = 'package-lock.json has no root package record';
    } else {
        foreach (['dependencies','devDependencies'] as $section) {
            $expected = $packageJson[$section] ?? [];
            $actual = $rootPackage[$section] ?? [];
            if (!is_array($expected) || !is_array($actual)) {
                $errors[] = 'Invalid ' . $section . ' section in package manifests';
                continue;
            }
            ksort($expected); ksort($actual);
            if ($expected !== $actual) {
                $errors[] = 'package-lock root ' . $section . ' does not match package.json';
            }
        }
    }
}

if (!is_file($vendorRuntime)) {
    $errors[] = 'vendor/autoload_runtime.php is missing';
}

if (!is_file($viteManifestPath)) {
    $errors[] = 'public/build/.vite/manifest.json is missing';
} else {
    $viteManifest = $decode($viteManifestPath);
    $requiredInputs = [
        'assets/admin/main.ts',
        'assets/storefront/product-page.js',
        'assets/storefront/storefront-runtime.js',
        'assets/storefront/checkout.js',
        'assets/storefront/cart.js',
        'assets/storefront/consent.js',
        'assets/storefront/slider.js',
        'assets/storefront/admin-runtime.js',
        'assets/admin/features/builder.js',
        'assets/admin/features/media-library.js',
        'assets/storefront/storefront.css',
        'assets/storefront/consent.css',
        'assets/admin/admin-runtime.css',
        'assets/admin/admin-builder-media.css',
    ];
    foreach ($requiredInputs as $input) {
        if (!isset($viteManifest[$input]) || !is_array($viteManifest[$input])) {
            $errors[] = 'Vite manifest missing entry: ' . $input;
            continue;
        }
        $file = (string)($viteManifest[$input]['file'] ?? '');
        if ($file === '' || !is_file($root . '/public/build/' . ltrim($file, '/'))) {
            $errors[] = 'Vite output missing for: ' . $input;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Dependency integrity check FAILED\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Dependency integrity check: OK\n";
echo "composer_lock=present\npackage_lock=present\nvendor_runtime=present\nvite_manifest=complete\n";
