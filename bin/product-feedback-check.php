<?php

declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='release version';
if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema version';
$required=[
 'migrations/Version20260925120000.php'=>['mc_review_helpful_vote','merchant_reply'],
 'src/Modules/Review/Application/ProductFeedbackService.php'=>['MAX_REVIEW_IMAGES','markHelpful','verified','DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 HOUR)'],
 'src/Modules/Review/Http/ProductFeedbackController.php'=>['PublicFormSpamGuard','product_review_helpful','_rendered_at'],
 'src/Modules/Admin/Http/CustomerExperienceAdminController.php'=>['merchant_reply','NotificationOutbox','question-answer','review-reply'],
 'themes/default/templates/product/blocks/reviews.html.twig'=>['multipart/form-data','images[]',"ui_text('verified_purchase')",'product_review_helpful',"ui_text('store_reply')"],
 'themes/default/templates/product/blocks/qa.html.twig'=>['_rendered_at','_website'],
];
foreach($required as $file=>$needles){$s=(string)@file_get_contents($root.'/'.$file);if($s===''){$fail[]='missing '.$file;continue;}foreach($needles as $n)if(!str_contains($s,$n))$fail[]=$file.' missing '.$n;}
if($fail){fwrite(STDERR,"Product feedback check FAILED:\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "Product feedback check: OK\n";
