#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Core/Platform/PlatformVersion.php';

use Commerce\Core\Platform\PlatformVersion;

$production = in_array('--production', $argv, true);
$candidate = in_array('--release-candidate', $argv, true);
if ($production && $candidate) { fwrite(STDERR, "Choose one release profile.\n"); exit(2); }
$errors = [];
$warnings = [];

$release = json_decode((string) @file_get_contents($root . '/resources/platform/release.json'), true);
$package = json_decode((string) @file_get_contents($root . '/package.json'), true);
$theme = json_decode((string) @file_get_contents($root . '/config/theme/default.json'), true);
$install = (string) @file_get_contents($root . '/INSTALL_UK.txt');
$readmeUk = (string) @file_get_contents($root . '/README.md');
$readmeEn = (string) @file_get_contents($root . '/README_EN.md');
$manifest = json_decode((string) @file_get_contents($root . '/release-manifest.json'), true);

$version = PlatformVersion::VERSION;
if (($release['version'] ?? '') !== $version) $errors[] = 'resources/platform/release.json version differs from PlatformVersion::VERSION.';
if (($package['version'] ?? '') !== $version) $errors[] = 'package.json version differs from PlatformVersion::VERSION.';
if (($theme['version'] ?? '') !== $version) $errors[] = 'config/theme/default.json version differs from PlatformVersion::VERSION.';
if (!str_contains($install, $version)) $errors[] = 'INSTALL_UK.txt does not mention the current platform version.';
if (!str_starts_with($readmeUk, '# Nexora Commerce ' . $version)) $errors[] = 'README.md heading differs from PlatformVersion::VERSION.';
if (!str_starts_with($readmeEn, '# Nexora Commerce ' . $version)) $errors[] = 'README_EN.md heading differs from PlatformVersion::VERSION.';

$schema = (string) ($release['database_schema'] ?? $release['schema_version'] ?? '');
if ($schema === '') $errors[] = 'Database schema version is absent from release.json.';
if ((string) ($release['schema_version'] ?? '') !== $schema) $errors[] = 'release.json database_schema and schema_version differ.';
if ((string) ($release['database']['schema'] ?? '') !== $schema) $errors[] = 'release.json database.schema differs from database_schema.';
if (is_array($manifest)) {
    if ((string) ($manifest['version'] ?? '') !== $version) $errors[] = 'release-manifest.json version differs from PlatformVersion::VERSION.';
    if ((string) ($manifest['channel'] ?? '') !== (string) ($release['channel'] ?? '')) $errors[] = 'release-manifest.json channel differs from release.json.';
    if ((string) ($manifest['schema'] ?? '') !== $schema) $errors[] = 'release-manifest.json schema differs from release.json.';
}

$lockChecks = [
    'composer.lock' => is_file($root . '/composer.lock'),
    'package-lock.json' => is_file($root . '/package-lock.json'),
    'vendor/autoload_runtime.php' => is_file($root . '/vendor/autoload_runtime.php'),
    'public/build/.vite/manifest.json' => is_file($root . '/public/build/.vite/manifest.json'),
];
foreach ($lockChecks as $label => $ok) {
    if ($ok) continue;
    if ($production) $errors[] = $label . ' is required for a PRODUCTION release.';
    else $warnings[] = $label . ' is absent; package remains SOURCE/development.';
}

if ($production && (($release['channel'] ?? '') !== 'production')) {
    $errors[] = 'release.json channel must be production for a certified PRODUCTION release.';
}
if ($candidate && (($release['channel'] ?? '') !== 'release-candidate')) {
    $errors[] = 'release.json channel must be release-candidate for an RC release.';
}
// A SOURCE package may carry production-release source code while intentionally omitting
// generated runtime artifacts. --production is the packaging contract that requires them.

if ($errors) {
    fwrite(STDERR, "RELEASE CONTRACT: FAILED\n");
    foreach ($errors as $error) fwrite(STDERR, "ERROR: {$error}\n");
    foreach ($warnings as $warning) fwrite(STDERR, "WARN: {$warning}\n");
    exit(1);
}

echo "RELEASE CONTRACT: PASSED\n";
echo "version={$version}\n";
echo "schema={$schema}\n";
echo 'profile=' . ($production ? 'production' : ($candidate ? 'release-candidate' : 'source')) . "\n";
foreach ($warnings as $warning) echo "WARN: {$warning}\n";
