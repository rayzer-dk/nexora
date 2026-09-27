<?php
$root=dirname(__DIR__);$fail=[];$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
require_once $root . '/src/Core/Platform/PlatformVersion.php';
if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
$controller=(string)@file_get_contents($root.'/src/Modules/Developer/Http/DeveloperToolsAdminController.php');$permission=(string)@file_get_contents($root.'/src/Modules/Admin/Authorization/AdminPermissionSubscriber.php');$catalog=(string)@file_get_contents($root.'/src/Core/Module/SystemModuleCatalog.php');$migration=(string)@file_get_contents($root.'/migrations/Version20260925190000.php');
foreach(['admin_system_developer','admin_system_developer_report','getRouteCollection','SystemModuleCatalog::all','Cache-Control'] as $n)if(!str_contains($controller,$n))$fail[]='controller '.$n;
if(!str_contains($permission,'DEVELOPER_TOOLS_VIEW'))$fail[]='permission mapping';
if(!str_contains($catalog,"\$system('developer_tools'")||!str_contains($catalog,'ModuleMaturity::Beta'))$fail[]='maturity';
if(!str_contains($migration,'developer_tools.view'))$fail[]='permission migration';
foreach(['shell_exec','system(','passthru','proc_open','eval('] as $danger)if(str_contains($controller,$danger))$fail[]='dangerous web execution';
if($fail){fwrite(STDERR,'Developer Tools Check FAILED: '.implode(', ',$fail).PHP_EOL);exit(1);}echo "Developer Tools Check: OK\n";
