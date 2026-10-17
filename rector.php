<?php
declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\Config\RectorConfig;

// Keep automated transformations narrow and compatible with target PHP.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withRules([ExplicitBoolCompareRector::class])
    ->withCache(__DIR__ . '/var/rector');
