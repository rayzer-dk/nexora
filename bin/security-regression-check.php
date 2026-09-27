#!/usr/bin/env php
<?php

declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/src/Core/Security/OutboundUrlPolicy.php';
require_once $root.'/src/Modules/Security/Webhook/HmacWebhookVerifier.php';

use Commerce\Core\Security\OutboundUrlPolicy;
use Commerce\Modules\Security\Webhook\HmacWebhookVerifier;

$fail=[];
$policy=new OutboundUrlPolicy();
foreach(['https://127.0.0.1/x','https://10.0.0.1/x','https://169.254.169.254/latest/meta-data/','https://[::1]/x','file:///etc/passwd','http://example.com'] as $url){
    try{$policy->assertPublicHttps($url);$fail[]='SSRF URL accepted: '.$url;}catch(Throwable){}
}
foreach(['https://example.com/api','https://api.github.com'] as $url){
    try{$policy->assertPublicHttps($url);}catch(Throwable $e){$fail[]='Public HTTPS rejected: '.$url.' ('.$e->getMessage().')';}
}

$secret='regression-secret';$body='{"ok":true}';$now=new DateTimeImmutable('@1700000000');$ts=(string)$now->getTimestamp();
$sig=hash_hmac('sha256',$ts.'.'.$body,$secret);$hmac=new HmacWebhookVerifier(300);
if(!$hmac->verify($body,$ts,$sig,$secret,$now))$fail[]='Valid HMAC rejected.';
if($hmac->verify($body,(string)($now->getTimestamp()-301),$sig,$secret,$now))$fail[]='Expired HMAC accepted.';
if($hmac->verify($body,$ts,str_repeat('0',64),$secret,$now))$fail[]='Invalid HMAC accepted.';

$validator=(string)file_get_contents($root.'/src/Core/Extension/ExtensionPackageValidator.php');
foreach(['($entrySize / $compressedSize) > 200.0','isSymlink','MAX_UNCOMPRESSED_BYTES','str_starts_with($name','preg_match(\'#(^|/)\.\.'] as $needle){if(!str_contains($validator,$needle))$fail[]='ZIP hardening missing: '.$needle;}
$media=(string)file_get_contents($root.'/src/Modules/Media/Application/MediaImageService.php');
foreach(['getimagesize','imagecreatefromstring','MAX_PIXELS'] as $needle){if(!str_contains($media,$needle))$fail[]='Image polyglot/decompression guard missing: '.$needle;}
$headers=(string)file_get_contents($root.'/src/Modules/Security/Http/SecurityHeadersSubscriber.php');
foreach(['Content-Security-Policy','X-Content-Type-Options','Strict-Transport-Security','Permissions-Policy'] as $needle){if(!str_contains($headers,$needle))$fail[]='Security header missing: '.$needle;}

if($fail){fwrite(STDERR,"Security regression suite failed:\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "Security regression suite passed.\n";
