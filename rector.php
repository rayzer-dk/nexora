<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector;
use Rector\Config\RectorConfig;
use Rector\Php84\Rector\FuncCall\AddEscapeArgumentRector;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\ValueObject\PhpVersion;

// Approved, narrow rules only. No comment stripping, method removal, vendor
// rewriting or broad changes to Symfony behavior.
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withRules([
        SimplifyIfReturnBoolRector::class,
        ExplicitNullableParamTypeRector::class,
        AddEscapeArgumentRector::class,
    ])
    ->withCache(__DIR__ . '/var/rector')
    ->withoutParallel();
