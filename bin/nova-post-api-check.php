<?php

declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$required=['src/Modules/Shipping/Provider/NovaPost/NovaPostTransport.php','src/Modules/Shipping/Provider/NovaPost/NovaPostDeliveryProvider.php','src/Modules/Shipping/Provider/NovaPost/NovaPostShipmentGateway.php','src/Modules/Shipping/Application/NovaPostShipmentOperationService.php','src/Modules/Shipping/Http/AdminShipmentController.php'];
foreach($required as $f)if(!is_file($root.'/'.$f))$fail[]='missing '.$f;
$env=(string)@file_get_contents($root.'/.env.example');foreach(['https://api.novaposhta.ua/v2.0/json/','NOVA_POST_SENDER_REF','NOVA_POST_CONTACT_SENDER_REF','NOVA_POST_CITY_SENDER_REF','NOVA_POST_SENDER_ADDRESS_REF'] as $n)if(!str_contains($env,$n))$fail[]='env '.$n;
$transport=(string)@file_get_contents($root.'/src/Modules/Shipping/Provider/NovaPost/NovaPostTransport.php');foreach(["'modelName'","'calledMethod'","'methodProperties'",'apiKey'] as $n)if(!str_contains($transport,$n))$fail[]='transport '.$n;
$provider=(string)@file_get_contents($root.'/src/Modules/Shipping/Provider/NovaPost/NovaPostDeliveryProvider.php');foreach(['getCities','getWarehouses','supportsShipmentCreation: true','supportsTracking: true'] as $n)if(!str_contains($provider,$n))$fail[]='provider '.$n;
$gateway=(string)@file_get_contents($root.'/src/Modules/Shipping/Provider/NovaPost/NovaPostShipmentGateway.php');foreach(["'InternetDocument','save'","'InternetDocument','delete'","'TrackingDocument','getStatusDocuments'","'Counterparty','save'"] as $n)if(!str_contains($gateway,$n))$fail[]='gateway '.$n;
$ops=(string)@file_get_contents($root.'/src/Modules/Shipping/Application/NovaPostShipmentOperationService.php');foreach(['cash_on_delivery',"['city_id']","['point_id']","'city_ref'=>","'address_ref'=>",'gateway->cancel','recordProviderEvent'] as $n)if(!str_contains($ops,$n))$fail[]='ops '.$n;
$controller=(string)@file_get_contents($root.'/src/Modules/Shipping/Http/AdminShipmentController.php');foreach(['admin_nova_post_create','admin_nova_post_cancel','admin_nova_post_tracking','admin_nova_post_label'] as $n)if(!str_contains($controller,$n))$fail[]='route '.$n;
if($fail){fwrite(STDERR,"Nova Post API check failed:\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "Nova Post API check: OK\n";
