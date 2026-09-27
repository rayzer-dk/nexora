#!/usr/bin/env php
<?php

declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/src/Modules/Integration/Application/IntegrationRetryPolicy.php';
use Commerce\Modules\Integration\Application\IntegrationRetryPolicy;
$fail=[];$policy=new IntegrationRetryPolicy();
$expected=[1=>30,2=>60,3=>120,4=>240,5=>480,6=>960,7=>1920];
foreach($expected as $attempt=>$delay){$d=$policy->afterFailure($attempt);if($d['dead']||$d['delay_seconds']!==$delay)$fail[]="retry attempt {$attempt}";}
$d=$policy->afterFailure(8);if(!$d['dead']||$d['delay_seconds']!==0)$fail[]='dead-letter attempt 8';
$checks=[
 'shipping fail-soft'=>[$root.'/src/Modules/Shipping/Application/DeliveryLocationGateway.php',['catch (Throwable)','TemporarilyUnavailable','recordFailure']],
 'search fallback'=>[$root.'/src/Modules/Search/Application/SearchSynonymService.php',['optional relevance layer','catch (\\Throwable)']],
 'storefront last-known-good'=>[$root.'/src/Core/Runtime/StorefrontLastKnownGoodCache.php',['X-Commerce-Fallback','last-known-good']],
 'integration retry isolation'=>[$root.'/src/Modules/Integration/Application/IntegrationSyncWorker.php',["'dead':'pending'",'catch(\\Throwable']],
 'notification isolation'=>[$root.'/src/Modules/Notification/EventSubscriber/OrderPlacedNotificationSubscriber.php',['enqueue']],
];
foreach($checks as $label=>[$file,$needles]){if(!is_file($file)){$fail[]=$label.' file missing';continue;}$raw=(string)file_get_contents($file);foreach($needles as $needle){if(!str_contains(strtolower($raw),strtolower($needle)))$fail[]=$label.' missing '.$needle;}}
if($fail){fwrite(STDERR,"Runtime failure-injection contract failed:\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "Runtime failure-injection contract passed: DB-independent degradation policies present.\n";
