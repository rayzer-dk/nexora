<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector;
use Rector\Config\RectorConfig;

// One approved, version-independent simplification. No comment stripping, method
// removal, vendor rewriting or broad changes to OpenCart/Symfony behavior.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withRules([SimplifyIfReturnBoolRector::class])
    ->withCache(__DIR__ . '/var/rector');
