#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Commerce\Core\Module\ModuleMaturity;
use Commerce\Core\Module\SystemModuleCatalog;
use Commerce\Core\Platform\PlatformVersion;

$errors = [];
$release = json_decode((string) file_get_contents($root . '/resources/platform/release.json'), true, 512, JSON_THROW_ON_ERROR);
$readiness = require $root . '/resources/platform/beta-readiness.php';

if (PlatformVersion::CHANNEL !== 'production' || ($release['channel'] ?? null) !== 'production') {
    $errors[] = 'Release channel is not production.';
}

$beta = [];
foreach (SystemModuleCatalog::all() as $code => $definition) {
    if ($definition->maturity === ModuleMaturity::Beta) {
        $beta[] = $code;
    }
}
if ($beta !== []) {
    $errors[] = 'Beta system modules remain: ' . implode(', ', $beta);
}

$pending = [];
foreach ($readiness as $code => $entry) {
    if (($entry['state'] ?? '') === 'integration_pending' || ($entry['state'] ?? '') === 'foundation_incomplete') {
        $pending[] = $code . ':' . ($entry['state'] ?? 'unknown');
    }
}
if ($pending !== []) {
    $errors[] = 'Unresolved readiness states remain: ' . implode(', ', $pending);
}

if ($errors !== []) {
    fwrite(STDERR, "PRODUCTION CERTIFICATION READINESS: FAILED\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "PRODUCTION CERTIFICATION READINESS: PASSED\n";
