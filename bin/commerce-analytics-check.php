#!/usr/bin/env php
<?php

declare(strict_types=1);
$root=dirname(__DIR__);require $root . '/vendor/autoload.php';$fail=[];
$must=[
 'migrations/Version20260925150000.php'=>['mc_search_query_log','analytics.view'],
 'src/Modules/Analytics/Application/SearchAnalyticsRecorder.php'=>['privacySafeDisplay','result_count'],
 'src/Modules/Analytics/Application/CommerceAnalyticsService.php'=>['net_minor','zero_searches','mc_order_attribution'],
 'src/Modules/Analytics/Http/AnalyticsAdminController.php'=>["/admin/analytics"],
 'themes/default/templates/admin/analytics/index.html.twig'=>["ui_text('ui.admin.analytics.index.zero_result_search')","ui_text('admin.analytics.index.chystyi_dokhid')"],
 'src/Modules/Storefront/Http/StorefrontCatalogController.php'=>['SearchAnalyticsRecorder','searchAnalytics->record'],
];
foreach($must as $file=>$needles){$text=@file_get_contents($root.'/'.$file);if(!is_string($text)){$fail[]='missing '.$file;continue;}foreach($needles as $n)if(!str_contains($text,$n))$fail[]=$file.' missing '.$n;}
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
if(!in_array('commerce-analytics-dashboard',(array)($release['capabilities']??[]),true))$fail[]='capability';
if($fail){fwrite(STDERR,"Commerce Analytics Check: FAILED\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Commerce Analytics Check: OK\n";
