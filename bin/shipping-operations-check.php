<?php

declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$required=['migrations/Version20260925110000.php','src/Modules/Shipping/Application/ShipmentOperationService.php','src/Modules/Shipping/Http/AdminShipmentController.php','themes/default/templates/admin/shipping/index.html.twig','themes/default/templates/admin/shipping/label.html.twig'];
foreach($required as $f)if(!is_file($root.'/'.$f))$fail[]='missing '.$f;
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
$service=(string)@file_get_contents($root.'/src/Modules/Shipping/Application/ShipmentOperationService.php');
$migration=(string)@file_get_contents($root.'/migrations/Version20260925110000.php');
foreach(['mc_shipment','mc_shipment_event','uq_shipment_tracking','return_request_id'] as $needle)if(!str_contains($migration,$needle))$fail[]='migration '.$needle;
foreach(['bulkStatus','updateStatus','carrierLabelUrl','returnPublicId'] as $needle)if(!str_contains($service,$needle))$fail[]='service '.$needle;
$controller=(string)@file_get_contents($root.'/src/Modules/Shipping/Http/AdminShipmentController.php');foreach(['admin_shipments','admin_shipment_register','admin_shipment_bulk_status','admin_shipment_label'] as $needle)if(!str_contains($controller,$needle))$fail[]='route '.$needle;
if($fail){fwrite(STDERR,"Shipping operations check failed:\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "Shipping operations check: OK\n";
