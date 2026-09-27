<?php
$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';
if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
$catalog=(string)@file_get_contents($root.'/src/Core/Module/SystemModuleCatalog.php');
$query=(string)@file_get_contents($root.'/src/Modules/Storefront/Infrastructure/DbalStorefrontCatalogQuery.php');
$admin=(string)@file_get_contents($root.'/src/Modules/Admin/Http/SearchAdminController.php');
$migration=(string)@file_get_contents($root.'/migrations/Version20260925180000.php');
foreach(['ModuleMaturity::Beta','recommendations'] as $needle)if(!str_contains($catalog,$needle))$fail[]='catalog '.$needle;
foreach(['automaticRecommendationIds','mc_sales_order_item','mc_product_attribute_value','mc_recommendation_setting'] as $needle)if(!str_contains($query,$needle))$fail[]='query '.$needle;
foreach(['admin_catalog_recommendation_settings','related_mode','complementary_mode'] as $needle)if(!str_contains($admin,$needle))$fail[]='admin '.$needle;
if(!str_contains($migration,'CREATE TABLE mc_recommendation_setting'))$fail[]='migration';
if($fail){fwrite(STDERR,"Recommendation Engine Check FAILED: ".implode(', ',$fail).PHP_EOL);exit(1);}echo "Recommendation Engine Check: OK\n";
