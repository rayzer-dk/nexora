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

$readiness = require $root . '/resources/platform/module-certification.php';
$errors = [];
$allowedStates = ['production_certified'];
$tracked = [];
foreach (SystemModuleCatalog::all() as $code => $definition) {
    if (!isset($readiness[$code])) continue;
    $tracked[$code] = true;
    $entry = $readiness[$code] ?? null;
    if (!is_array($entry)) { $errors[] = 'Tracked stable module has no certification record: ' . $code; continue; }
    if ($definition->maturity !== ModuleMaturity::Stable) { $errors[] = 'Tracked module is not Stable: ' . $code; continue; }
    if (($entry['state'] ?? '') !== 'production_certified') { $errors[] = 'Tracked module is not production_certified: ' . $code; continue; }
    if (!in_array((string)($entry['state'] ?? ''), $allowedStates, true)) $errors[] = 'Invalid readiness state for ' . $code;
    $runtime = trim((string)($entry['runtime'] ?? ''));
    if ($runtime === '') $errors[] = 'Missing runtime certification evidence for ' . $code;
    if (preg_match('/before\s+Stable|Run\s+.*before\s+Stable/i', $runtime) === 1) $errors[] = 'Pre-Stable wording remains in certified module: ' . $code;
    if (!isset($entry['evidence']) || !is_array($entry['evidence']) || $entry['evidence'] === []) $errors[] = 'Missing static evidence for ' . $code;
}
foreach (array_keys($readiness) as $code) if (!isset($tracked[$code])) $errors[] = 'Readiness record exists for unknown/untracked module: ' . $code;
if ($errors) { fwrite(STDERR, "Production module certification check FAILED\n- " . implode("\n- ", $errors) . "\n"); exit(1); }
$states = [];
foreach ($readiness as $entry) $states[$entry['state']] = ($states[$entry['state']] ?? 0) + 1;
echo "Production module certification check: OK\n";
echo 'production_certified_modules=' . count($tracked) . "\n";
foreach ($states as $state => $count) echo $state . '=' . $count . "\n";
