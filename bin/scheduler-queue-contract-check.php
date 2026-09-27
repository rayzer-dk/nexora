#!/usr/bin/env php
<?php

declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];
$registry=(string)file_get_contents($root.'/src/Core/Scheduler/ScheduledTaskRegistry.php');
preg_match_all("/'command'=>'([^']+)'/",$registry,$m);$commands=$m[1]??[];
foreach($commands as $command){$found=false;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file){if(!$file->isFile()||$file->getExtension()!=='php')continue;$s=(string)file_get_contents($file->getPathname());if(str_contains($s,"name:'$command'")||str_contains($s,"name: '$command'")){$found=true;break;}}if(!$found)$errors[]='Scheduled command not found: '.$command;}
$cron=(string)file_get_contents($root.'/src/Core/Scheduler/Console/CronRunCommand.php');foreach(['GET_LOCK','RELEASE_LOCK','next_due_at','last_status','last_duration_ms'] as $token)if(!str_contains($cron,$token))$errors[]='Cron runner missing '.$token;
foreach(['DomainEventOutboxWorker.php','NotificationOutboxWorker.php'] as $name){$matches=glob($root.'/src/**/'.$name,GLOB_BRACE)?:[];}
$event=(string)file_get_contents($root.'/src/Core/Event/DomainEventOutboxWorker.php');foreach(['MAX_DELIVERY_ATTEMPTS','LOCK_TIMEOUT_SECONDS','retryAt','dead'] as $token)if(!str_contains($event,$token))$errors[]='Event queue missing '.$token;
$notify=(string)file_get_contents($root.'/src/Modules/Notification/Application/NotificationOutboxWorker.php');foreach(['MAX_ATTEMPTS','STALE_LOCK_SECONDS','lock_token','available_at'] as $token)if(!str_contains($notify,$token))$errors[]='Notification queue missing '.$token;
if(str_contains($registry,"'label'=>'Email / Telegram / SMS'")||str_contains($registry,"'label'=>'Google / Marketing'"))$errors[]='Hardcoded scheduler labels remain';
if($errors){fwrite(STDERR,"Scheduler/queue contract check FAILED\n- ".implode("\n- ",$errors)."\n");exit(1);}echo "Scheduler/queue contract check: OK\n";echo 'registered_tasks='.count($commands)." cron_lock=yes stale_recovery=yes retries=yes dead_letter=yes\n";
