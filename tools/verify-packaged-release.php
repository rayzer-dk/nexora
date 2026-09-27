<?php

declare(strict_types=1);

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php tools/verify-packaged-release.php <production-dir>\n");
    exit(2);
}

$errors = [];
$release = json_decode((string) @file_get_contents($dir . '/resources/platform/release.json'), true);
$manifest = json_decode((string) @file_get_contents($dir . '/release-manifest.json'), true);
$composer = json_decode((string) @file_get_contents($dir . '/composer.json'), true);
$package = json_decode((string) @file_get_contents($dir . '/package.json'), true);

if (!is_array($release)) $errors[] = 'release.json is missing or invalid.';
if (!is_array($manifest)) $errors[] = 'release-manifest.json is missing or invalid.';
if (!is_array($composer)) $errors[] = 'composer.json is missing or invalid.';
if (!is_array($package)) $errors[] = 'package.json is missing or invalid.';

$schema = (string) ($release['database_schema'] ?? '');
if ($schema === '') $errors[] = 'database_schema is missing.';
if ((string) ($release['schema_version'] ?? '') !== $schema) $errors[] = 'schema_version differs from database_schema.';
if ((string) ($release['database']['schema'] ?? '') !== $schema) $errors[] = 'database.schema differs from database_schema.';
if ((string) ($manifest['schema'] ?? '') !== $schema) $errors[] = 'release-manifest schema differs from release.json.';
if (($manifest['channel'] ?? '') !== 'release-candidate') $errors[] = 'release-manifest channel is not release-candidate.';
if (($release['channel'] ?? '') !== 'release-candidate') $errors[] = 'release.json channel is not release-candidate.';
if (($manifest['version'] ?? '') !== ($release['version'] ?? '')) $errors[] = 'release-manifest version differs from release.json.';
if (($package['version'] ?? '') !== ($release['version'] ?? '')) $errors[] = 'package.json version differs from release.json.';
if (!is_file($dir . '/composer.lock')) $errors[] = 'composer.lock is missing.';
if (!is_file($dir . '/package-lock.json')) $errors[] = 'package-lock.json is missing.';
if (!is_file($dir . '/vendor/autoload_runtime.php')) $errors[] = 'vendor runtime is missing.';
if (!is_file($dir . '/public/build/.vite/manifest.json')) $errors[] = 'compiled Vite manifest is missing.';
if (is_dir($dir . '/node_modules')) $errors[] = 'node_modules must not be shipped.';
if (is_file($dir . '/.env')) $errors[] = '.env must not be shipped; installer must create .env.local.';
if (is_file($dir . '/.env.local')) $errors[] = '.env.local must not be shipped.';

if ($errors !== []) {
    fwrite(STDERR, "PACKAGED RELEASE VERIFY: FAILED\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "PACKAGED RELEASE VERIFY: PASSED\n";
echo "version=" . ($release['version'] ?? '') . "\n";
echo "schema={$schema}\n";
