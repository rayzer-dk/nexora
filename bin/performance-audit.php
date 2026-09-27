#!/usr/bin/env php
<?php

declare(strict_types=1);

$root=dirname(__DIR__);$budgets=json_decode((string)file_get_contents($root.'/config/performance/budgets.json'),true,32,JSON_THROW_ON_ERROR);
$profile=$argv[1]??'10k';if(!isset($budgets['profiles'][$profile])){fwrite(STDERR,"Usage: php bin/performance-audit.php [10k|100k|500k]\n");exit(2);} $budget=$budgets['profiles'][$profile];
$base=rtrim((string)getenv('PERF_BASE_URL'),'/');
if($base===''){echo "Performance profile {$profile}: static readiness mode.\n";echo json_encode($budget,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";echo "Set PERF_BASE_URL to run live HTTP latency probes.\n";exit(0);} if(!preg_match('#^https?://#i',$base)){fwrite(STDERR,"PERF_BASE_URL must be http(s).\n");exit(2);} 
$paths=['product'=>'/','category'=>'/','search'=>'/api/v1/catalog/products?q=test&limit=24'];$fail=[];
foreach($paths as $name=>$path){$samples=[];for($i=0;$i<20;$i++){ $start=hrtime(true);$ctx=stream_context_create(['http'=>['timeout'=>10,'ignore_errors'=>true,'follow_location'=>0,'header'=>"User-Agent: MCP-Performance-Audit/1\r\n"]]);$data=@file_get_contents($base.$path,false,$ctx);$ms=(hrtime(true)-$start)/1e6;if($data===false){$fail[]=$name.': request failed';break;}$samples[]=$ms;}if($samples===[])continue;sort($samples);$p95=$samples[(int)floor((count($samples)-1)*0.95)];$key=$name.'_p95_ms';$limit=$budget[$key]??null;printf("%-10s p95=%7.1f ms%s\n",$name,$p95,$limit!==null?' budget='.$limit.' ms':'');if($limit!==null&&$p95>$limit)$fail[]=$name.' p95 exceeds budget';}
if($fail){fwrite(STDERR,"Performance audit failed: ".implode('; ',$fail)."\n");exit(1);}echo "Performance smoke audit passed for profile {$profile}.\n";
