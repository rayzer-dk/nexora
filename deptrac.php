<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\BoolConfig;
use Deptrac\Deptrac\Contract\Config\Collector\ClassLikeConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $domainClasses = ClassLikeConfig::create('^Commerce\\\\Modules\\\\[^\\\\]+\\\\Domain\\\\');
    $supportClasses = ClassLikeConfig::create(
        '^Commerce\\\\(Core\\\\I18n\\\\CanonicalUiText|Modules\\\\Catalog\\\\Measurement\\\\Quantity)$'
    );
    $userContracts = ClassLikeConfig::create(
        '^Symfony\\\\Component\\\\Security\\\\Core\\\\User\\\\(UserInterface|PasswordAuthenticatedUserInterface)$'
    );

    $domain = Layer::withName('Domain')->collectors($domainClasses);
    $support = Layer::withName('DomainSupport')->collectors($supportClasses);
    $security = Layer::withName('SecurityUserContracts')->collectors($userContracts);
    $platform = Layer::withName('Platform')->collectors(BoolConfig::create(
        must: [ClassLikeConfig::create('^Commerce\\\\')],
        mustNot: [$domainClasses, $supportClasses],
    ));
    $external = Layer::withName('External')->collectors(BoolConfig::create(
        must: [ClassLikeConfig::create('^(?!Commerce\\\\).+')],
        mustNot: [$userContracts],
    ));

    // Enforce the existing Domain boundary, including its four explicit dependencies.
    // Other source layers are classified for coverage; their direction is not restricted here.
    $config
        ->paths(__DIR__ . '/src')
        ->cacheFile(__DIR__ . '/var/deptrac.cache')
        ->layers($domain, $support, $security, $platform, $external)
        ->rulesets(
            Ruleset::forLayer($domain)->accesses($support, $security),
            Ruleset::forLayer($support)->accesses($domain, $security, $platform, $external),
            Ruleset::forLayer($platform)->accesses($domain, $support, $security, $external),
            Ruleset::forLayer($security)->accesses($external),
            Ruleset::forLayer($external)->accesses($domain, $support, $security, $platform),
        );
};
