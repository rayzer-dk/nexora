<?php

declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$must=[
 'src/Modules/Admin/Http/VisualStoreEditorAdminController.php'=>['admin_visual_store_editor'],
 'src/Modules/Admin/Http/MerchandisingAdminController.php'=>['admin_catalog_merchandising','mc_category_merchandising'],
 'src/Modules/Admin/Http/SearchAdminController.php'=>['mc_search_boost','complementary'],
 'src/Modules/Admin/Http/ProductWorkspaceAdminController.php'=>['bulk-edit','mc_admin_saved_view','ProductWriter'],
 'src/Modules/Admin/Http/EmailPreviewAdminController.php'=>['admin_email_preview'],
 'src/Core/Scheduler/CronCommandHint.php'=>['commerce:cron:run'],
 'src/Core/Scheduler/CronRunner.php'=>['nexora_commerce_cron_runner','mc_scheduled_task_state'],
 'src/Modules/Media/Application/MediaVideoService.php'=>['video/mp4','video/webm','104857600'],
 'src/Modules/ProductPage/Application/ProductPageLayoutLoader.php'=>['data_source'],
 'src/Modules/Feeds/Application/ProductFeedGenerator.php'=>['agenticJsonl','application/x-ndjson'],
 'src/Modules/Catalog/Infrastructure/DbalCatalogAdminQuery.php'=>['quality','has_image','has_seo'],
 'src/Modules/Appearance/Builder/LayoutSchemaValidator.php'=>['sanitizeNested'],
];
foreach($must as $file=>$tokens){$c=@file_get_contents($root.'/'.$file);if(!is_string($c)){$fail[]='missing '.$file;continue;}foreach($tokens as $t)if(!str_contains($c,$t))$fail[]=$file.' missing '.$t;}
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
if(($release['version']??null)!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
if($fail){fwrite(STDERR,"UX modernization check failed:\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "UX modernization check passed.\n";
