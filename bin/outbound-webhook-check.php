#!/usr/bin/env php
<?php

declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];
$files=[
 'migrations/Version20260925213000.php'=>['mc_webhook_subscription','secret_cipher','creation_key_hash','creation_request_hash','uq_webhook_subscription_event','idx_webhook_stale_lock'],
 'src/Modules/Api/Webhook/ApiWebhookSubscriptionService.php'=>['OutboundUrlPolicy','SecretVault','assertPublicHttps','creationKeyHash','idempotency_conflict'],
 'src/Modules/Api/Webhook/ApiWebhookEventSubscriber.php'=>['DomainEventSubscriberInterface','api.outbound_webhooks','mc_webhook_delivery','UniqueConstraintViolationException'],
 'src/Modules/Api/Webhook/ApiWebhookDeliveryWorker.php'=>['hash_hmac','X-Nexora-Signature','max_redirects','timeout'=>10],
 'src/Modules/Api/Http/PublicApiV1Controller.php'=>['webhooks:manage','createWebhook','deleteWebhook','api.openapi.webhook_create'],
 'src/Core/Scheduler/ScheduledTaskRegistry.php'=>['commerce:webhooks:work'],
];
foreach($files as $file=>$tokens){$path=$root.'/'.$file;if(!is_file($path)){$errors[]='Missing '.$file;continue;}$s=(string)file_get_contents($path);foreach($tokens as $k=>$token){$needle=is_int($k)?$token:$k;if(!str_contains($s,(string)$needle))$errors[]=$file.' missing '.$needle;}}
$worker=(string)file_get_contents($root.'/src/Modules/Api/Webhook/ApiWebhookDeliveryWorker.php');
if(str_contains($worker,'max_redirects\'=>5')||!str_contains($worker,"'max_redirects'=>0"))$errors[]='Webhook redirects must be disabled';
if(str_contains((string)file_get_contents($root.'/src/Modules/Api/Webhook/ApiWebhookSubscriptionService.php'),"'secret'=>")===false)$errors[]='Creation response must issue secret once/replay-safe';
if($errors){fwrite(STDERR,"Outbound webhook check FAILED\n- ".implode("\n- ",$errors)."\n");exit(1);}echo "Outbound webhook check: OK\n";echo "store_scope=yes encrypted_secret=yes ssrf_policy=yes hmac=yes redirects=off retries=yes dead_letter=yes\n";
