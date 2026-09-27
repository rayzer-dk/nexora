#!/usr/bin/env php
<?php

declare(strict_types=1);

use Commerce\Core\Module\ModuleMaturity;
use Commerce\Core\Module\SystemModuleCatalog;

$root = dirname(__DIR__);
require_once $root . '/src/Core/I18n/CanonicalUiText.php';
require_once $root . '/src/Core/Module/ModuleMaturity.php';
require_once $root . '/src/Core/Module/ModuleTier.php';
require_once $root . '/src/Core/Module/ModuleRemovalPolicy.php';
require_once $root . '/src/Core/Module/SystemModuleDefinition.php';
require_once $root . '/src/Core/Module/SystemModuleCatalog.php';

$readiness = require $root . '/resources/platform/beta-readiness.php';
$errors = [];
$allowedStates = ['static_ready','integration_pending','foundation_incomplete'];
$beta = [];
foreach (SystemModuleCatalog::all() as $code => $definition) {
    if ($definition->maturity !== ModuleMaturity::Beta) continue;
    $beta[$code] = true;
    $entry = $readiness[$code] ?? null;
    if (!is_array($entry)) { $errors[] = 'Beta module has no readiness record: ' . $code; continue; }
    if (!in_array((string)($entry['state'] ?? ''), $allowedStates, true)) $errors[] = 'Invalid readiness state for ' . $code;
    if (trim((string)($entry['runtime'] ?? '')) === '') $errors[] = 'Missing runtime exit criterion for ' . $code;
    if (!isset($entry['evidence']) || !is_array($entry['evidence']) || $entry['evidence'] === []) $errors[] = 'Missing static evidence for ' . $code;
}
foreach (array_keys($readiness) as $code) if (!isset($beta[$code])) $errors[] = 'Readiness record exists for non-Beta/unknown module: ' . $code;
if ($errors) { fwrite(STDERR, "Beta readiness check FAILED\n- " . implode("\n- ", $errors) . "\n"); exit(1); }
$states = [];
foreach ($readiness as $entry) $states[$entry['state']] = ($states[$entry['state']] ?? 0) + 1;
echo "Beta readiness check: OK\n";
echo 'beta_modules=' . count($beta) . "\n";
foreach ($states as $state => $count) echo $state . '=' . $count . "\n";
