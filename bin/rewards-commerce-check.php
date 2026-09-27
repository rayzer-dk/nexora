<?php
$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';
if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
$need=[
 'migrations/Version20260925170000.php',
 'src/Modules/Rewards/Application/GiftCardService.php',
 'src/Modules/Rewards/Application/LoyaltyService.php',
 'src/Modules/Rewards/Http/RewardsController.php',
 'src/Modules/Rewards/Http/RewardsAdminController.php',
 'themes/default/templates/account/rewards.html.twig',
 'themes/default/templates/admin/rewards/index.html.twig',
];
foreach($need as $f)if(!is_file($root.'/'.$f))$fail[]='missing '.$f;
$catalog=(string)@file_get_contents($root.'/src/Core/Module/SystemModuleCatalog.php');
foreach(['gift_cards','loyalty'] as $code)if(!str_contains($catalog,"\$system('".$code."'"))$fail[]='catalog '.$code;
$order=(string)@file_get_contents($root.'/src/Modules/Order/Application/CheckoutOrderService.php');
foreach(['gift_card_code','loyalty_points','gift_card_minor','loyalty_minor'] as $x)if(!str_contains($order,$x))$fail[]='checkout '.$x;
$life=(string)@file_get_contents($root.'/src/Modules/Payment/Application/PaymentLifecycleService.php');
foreach(['earnForPaidOrder','restoreForOrder','restoreSpendForOrder','reverseEarnForOrder'] as $x)if(!str_contains($life,$x))$fail[]='lifecycle '.$x;
if($fail){fwrite(STDERR,"Rewards Commerce Check FAILED\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "Rewards Commerce Check OK\n";
