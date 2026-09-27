<?php

declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$need=[
 'src/Modules/Admin/Audit/AdminAuditSubscriber.php'=>['mc_audit_log','request->getMethod','Fail-soft'],
 'src/Modules/Admin/Http/AdminAuditController.php'=>['admin_system_activity','mc_audit_log'],
 'src/Modules/Admin/Http/OrderAdminController.php'=>['o.store_id=?','admin_order_saved_view_save','admin_order_preview','$this->order($id,$request)'],
 'src/Modules/Admin/Http/ProductWorkspaceAdminController.php'=>['admin_catalog_product_preview','columns_json'],
 'public/assets/admin-runtime.js'=>['initQuickPreview','DOMParser'],
 'public/assets/admin-features/builder.js'=>['localDraftKey','localStorage','offerLocalRecovery'],
];
foreach($need as $file=>$patterns){$text=@file_get_contents($root.'/'.$file);if($text===false){$fail[]='missing '.$file;continue;}foreach($patterns as $pattern)if(!str_contains($text,$pattern))$fail[]=$file.' missing '.$pattern;}
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
if($fail){fwrite(STDERR,"Admin productivity check FAILED\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "Admin productivity check OK\n";
