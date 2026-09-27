<?php
$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';
if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
$files=['migrations/Version20260925160000.php','src/Modules/B2B/Application/B2bCommerceService.php','src/Modules/B2B/Http/B2bAdminController.php','src/Modules/Payment/Provider/B2bInvoicePaymentProvider.php','themes/default/templates/admin/b2b/index.html.twig'];
foreach($files as $f)if(!is_file($root.'/'.$f))$fail[]='missing '.$f;
$checks=[
 'src/Modules/B2B/Application/B2bCommerceService.php'=>['credit_limit_minor','approval_threshold_minor','mc_b2b_price_tier','priceFor','checkoutTerms'],
 'src/Modules/Order/Application/CheckoutOrderService.php'=>['b2b_company_id','b2b_approval_status','purchase_order_number','payment_terms_days','pending_approval'],
 'src/Modules/Cart/Application/CartMutationService.php'=>['B2bCommerceService','priceFor'],
 'src/Modules/Checkout/Http/CheckoutController.php'=>['b2b_invoice','b2b_company'],
 'src/Core/Module/SystemModuleCatalog.php'=>["'b2b'",'ModuleMaturity::Beta'],
];
foreach($checks as $f=>$need){$s=(string)@file_get_contents($root.'/'.$f);foreach($need as $n)if(!str_contains($s,$n))$fail[]="$f missing $n";}
if($fail){fwrite(STDERR,"B2B Commerce Check: FAILED\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "B2B Commerce Check: OK\n";
