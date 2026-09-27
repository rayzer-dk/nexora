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
$readiness = require $root . '/resources/platform/module-certification.php';

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

$invalidStates = [];
foreach ($readiness as $code => $entry) {
    $state = (string)($entry['state'] ?? '');
    if ($state !== 'production_certified') {
        $invalidStates[] = $code . ':' . ($state !== '' ? $state : 'missing');
    }
    if (!isset($entry['evidence']) || !is_array($entry['evidence']) || $entry['evidence'] === []) {
        $errors[] = 'Missing certification evidence for module: ' . $code;
    }
    $runtime = trim((string)($entry['runtime'] ?? ''));
    if ($runtime === '') {
        $errors[] = 'Missing runtime certification statement for module: ' . $code;
    }
    if (preg_match('/before\s+Stable|integration_pending|foundation_incomplete/i', $runtime) === 1) {
        $errors[] = 'Unresolved/pre-Stable wording remains for module: ' . $code;
    }
}
if ($invalidStates !== []) {
    $errors[] = 'Non-certified module states remain: ' . implode(', ', $invalidStates);
}

foreach (SystemModuleCatalog::all() as $code => $definition) {
    if (isset($readiness[$code]) && $definition->maturity !== ModuleMaturity::Stable) {
        $errors[] = 'Certified module is not Stable in system catalog: ' . $code;
    }
}

if ($errors !== []) {
    fwrite(STDERR, "PRODUCTION CERTIFICATION READINESS: FAILED\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "PRODUCTION CERTIFICATION READINESS: PASSED\n";
