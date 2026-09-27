#!/usr/bin/env php
<?php

declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root . '/src/Core/Licensing/DependencyLicensePolicy.php';
require_once $root . '/src/Core/Licensing/DependencyLicenseAuditor.php';
$auditor=new Commerce\Core\Licensing\DependencyLicenseAuditor();
$composer=$auditor->auditComposerLock($root . '/composer.lock');
$npm=$auditor->auditPackageLock($root . '/package-lock.json');
foreach (['Composer'=>$composer,'npm'=>$npm] as $name=>$report) {
    echo $name . ': checked ' . $report['checked'] . ', violations ' . count($report['violations']) . PHP_EOL;
    foreach ($report['violations'] as $v) echo '  - ' . $v['name'] . ': ' . $v['license'] . PHP_EOL;
}
exit(($composer['ok'] && $npm['ok']) ? 0 : 1);
