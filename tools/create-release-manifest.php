<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "Usage: php tools/create-release-manifest.php <production-dir>\n");
    exit(2);
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/src/Core/Platform/PlatformVersion.php';

$releasePath = $projectRoot . '/resources/platform/release.json';
$release = json_decode((string) file_get_contents($releasePath), true, 512, JSON_THROW_ON_ERROR);
$schema = (string) ($release['database_schema'] ?? $release['schema_version'] ?? '');
if ($schema === '') {
    fwrite(STDERR, "Database schema is absent from resources/platform/release.json\n");
    exit(3);
}
$phpMin = (string) ($release['php']['min'] ?? '8.4.0');
$phpMax = (string) ($release['php']['max_exclusive'] ?? '8.6.0');

$manifest = [
    'product' => 'Nexora Commerce',
    'version' => Commerce\Core\Platform\PlatformVersion::VERSION,
    'channel' => (string) ($release['channel'] ?? Commerce\Core\Platform\PlatformVersion::CHANNEL),
    'built_at_utc' => gmdate('c'),
    'php' => '>=' . $phpMin . ' <' . $phpMax,
    'schema' => (int) $schema,
    'contains_vendor' => is_file($root . '/vendor/autoload_runtime.php'),
    'contains_compiled_assets' => is_file($root . '/public/build/.vite/manifest.json'),
    'installer' => 'public/setup.php',
    'document_root' => 'public/',
];

file_put_contents(
    $root . '/release-manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
