#!/usr/bin/env php
<?php

declare(strict_types=1);
$root=dirname(__DIR__);$failures=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$checks=[
 'uploaded backup restore'=>['src/Modules/Admin/Http/SystemAdminController.php','admin_system_recovery_upload_restore'],
 'purchase policy'=>['src/Modules/Storefront/Infrastructure/DbalStorefrontCatalogQuery.php','purchaseState'],
 'backorder checkout'=>['src/Modules/Order/Application/CheckoutOrderService.php','allowBackorder'],
 'stock double opt in'=>['src/Modules/Inventory/Application/StockNotificationService.php','stock_alert_confirm'],
 'product builder runtime'=>['src/Modules/ProductPage/Application/ProductPageLayoutLoader.php','publishedOrNull'],
 'checkout builder runtime'=>['src/Modules/Checkout/Application/CheckoutLayoutService.php','checkout_contact'],
 'ajax cart'=>['assets/storefront/cart.js','data-cart-update'],
 'customer inquiries'=>['src/Modules/Customer/Application/CustomerInquiryService.php','mc_customer_inquiry'],
 'promotion customer groups'=>['src/Modules/Promotion/Application/PromotionEngine.php','customer_groups'],
];
foreach($checks as $name=>[$file,$needle]){$p=$root.'/'.$file;$c=is_file($p)?file_get_contents($p):false;if(!is_string($c)||!str_contains($c,$needle))$failures[]=$name;}
$release=json_decode((string)file_get_contents($root.'/resources/platform/release.json'),true);
if(($release['version']??null)!==\Commerce\Core\Platform\PlatformVersion::VERSION)$failures[]='version';if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$failures[]='schema';
if($failures){fwrite(STDERR,"Commerce UX check FAILED: ".implode(', ',$failures)."\n");exit(1);}echo "Commerce UX check: OK\n";
