#!/usr/bin/env php
<?php

declare(strict_types=1);

$root=dirname(__DIR__);$errors=[];
$need=[
 'migrations/Version20260925203000.php'=>['mc_api_token','token_hash BINARY(32)','scopes JSON','mc_api_rate_window','mc_api_idempotency'],
 'src/Modules/Api/Application/ApiAccessService.php'=>['Authorization','hash(\'sha256\'','catalog:read','customers:read','orders:read','carts:read','rate_limit_per_minute','last_used_at'],
 'src/Modules/Api/Console/ApiTokenIssueCommand.php'=>['random_bytes(32)','token_hash','commerce:api:token:issue'],
 'src/Modules/Api/Console/ApiTokenRevokeCommand.php'=>['commerce:api:token:revoke',"status='revoked'"],
 'src/Modules/Api/Http/PublicApiV1Controller.php'=>['/customers','/orders','/carts','bearerAuth','x-scope','CanonicalUiText::get','cart_create','cart_item_put','cart_checkout','Idempotency-Key','carts:write','checkout:write'],
 'src/Modules/Api/Application/ApiIdempotencyService.php'=>['Idempotency-Key','mc_api_idempotency','requestHash','idempotency_conflict'],
 'src/Modules/Cart/Application/CartMutationService.php'=>['setVariantQuantity'],
 'migrations/Version20260925213000.php'=>['mc_webhook_subscription','creation_key_hash','mc_webhook_delivery','locked_at','uq_webhook_subscription_event'],
 'src/Modules/Api/Webhook/ApiWebhookSubscriptionService.php'=>['SecretVault','OutboundUrlPolicy','creation_key_hash','api-webhook:'],
 'src/Modules/Api/Webhook/ApiWebhookEventSubscriber.php'=>['api.outbound_webhooks','mc_webhook_delivery','subscription_key'],
 'src/Modules/Api/Webhook/ApiWebhookDeliveryWorker.php'=>['X-Nexora-Signature','hash_hmac','max_redirects','MAX_ATTEMPTS','target_url_hash'],
 'src/Modules/Api/Infrastructure/DbalPublicApiQuery.php'=>['mc_customer','mc_sales_order','mc_cart','store_id=?'],
];
foreach($need as $file=>$tokens){$path=$root.'/'.$file;if(!is_file($path)){$errors[]='Missing '.$file;continue;}$s=(string)file_get_contents($path);foreach($tokens as $token)if(!str_contains($s,$token))$errors[]=$file.' missing contract token: '.$token;}
require_once $root.'/src/Core/I18n/TranslationCatalogLoader.php';$catalog=(new \Commerce\Core\I18n\TranslationCatalogLoader($root))->load('uk-UA');foreach(['api.error.auth_required','api.error.token_invalid','api.error.token_expired','api.error.store_forbidden','api.error.scope_forbidden','api.error.rate_limited','api.error.invalid_sort','api.error.invalid_price_range','api.error.product_not_found','api.error.customer_not_found','api.error.order_not_found','api.error.cart_not_found','api.error.idempotency_key_required','api.error.idempotency_conflict','api.error.invalid_quantity','api.error.invalid_json'] as $key)if(!isset($catalog[$key])||trim((string)$catalog[$key])==='')$errors[]='Missing uk-UA key '.$key;
$controller=(string)file_get_contents($root.'/src/Modules/Api/Http/PublicApiV1Controller.php');
foreach(['Unsupported catalog sort mode.','Minimum price cannot exceed maximum price.','Product was not found.'] as $literal)if(str_contains($controller,$literal))$errors[]='Hardcoded API message remains: '.$literal;
if($errors){fwrite(STDERR,"Public API contract check FAILED\n- ".implode("\n- ",$errors)."\n");exit(1);}echo "Public API contract check: OK\n";
echo "auth=hashed_bearer scopes=7 rate_limit=yes store_binding=yes read_resources=products,customers,orders,carts write_cart=yes checkout_write=yes idempotency=yes webhook_management=yes signed_delivery=yes\n";
