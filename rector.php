<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Suggestions only. Do not run without --dry-run during onboarding.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withTypeCoverageLevel(0)
    ->withCache(__DIR__ . '/var/rector');
