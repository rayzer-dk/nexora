#!/usr/bin/env php
<?php

declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];
$context=(string)file_get_contents($root.'/src/Modules/Storefront/Infrastructure/StorefrontContextResolver.php');
$query=(string)file_get_contents($root.'/src/Modules/Api/Infrastructure/DbalPublicApiQuery.php');
$access=(string)file_get_contents($root.'/src/Modules/Api/Application/ApiAccessService.php');
foreach(['mc_store_domain','d.store_id','m.store_id=?','mc_store_locale WHERE store_id=?','mc_store_currency WHERE store_id=?'] as $token)if(!str_contains($context,$token))$errors[]='Storefront context missing isolation token: '.$token;
foreach(['tokenStoreId !== null && $tokenStoreId !== $storeId','api.error.store_forbidden'] as $token)if(!str_contains($access,$token))$errors[]='API token store binding missing: '.$token;
foreach(['so.store_id=?','c.store_id=?','store_id=? AND public_id=?','EXISTS (SELECT 1 FROM mc_sales_order so WHERE so.customer_id=c.id AND so.store_id=?)'] as $token)if(!str_contains($query,$token))$errors[]='API query missing store constraint: '.$token;
if(str_contains($context,'No active storefront is mapped to host')||str_contains($context,'No active market is configured for the storefront'))$errors[]='Hardcoded storefront context error remains';
if($errors){fwrite(STDERR,"Multi-store isolation check FAILED\n- ".implode("\n- ",$errors)."\n");exit(1);}echo "Multi-store isolation check: OK\n";echo "domain_mapping=yes market_scope=yes locale_scope=yes currency_scope=yes api_store_binding=yes\n";
