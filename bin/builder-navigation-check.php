<?php

declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
require_once $root . '/src/Core/Platform/PlatformVersion.php';
$release=json_decode((string)@file_get_contents($root.'/resources/platform/release.json'),true);
if(($release['version']??'')!==\Commerce\Core\Platform\PlatformVersion::VERSION)$fail[]='version';
if((string)($release['database_schema']??'')!==(string)\Commerce\Core\Platform\PlatformVersion::DATABASE_SCHEMA)$fail[]='schema';
$must=[
 'migrations/Version20260925140000.php'=>['mc_layout_snippet'],
 'src/Modules/Appearance/Builder/LayoutSnippetStore.php'=>['validate','mc_layout_snippet'],
 'src/Modules/Admin/Http/LayoutBuilderAdminController.php'=>['snippet_save','admin_appearance_builder_snippet_delete'],
 'assets/admin/features/builder.js'=>['data-duplicate','data-save-snippet','data-insert-snippet'],
 'src/Modules/Navigation/Application/NavigationManager.php'=>['wouldCreateCycle','navigationmanager.batkivskyi_punkt_ne_nalezhyt_tsomu_meniu'],
 'themes/default/templates/admin/appearance/navigation.html.twig'=>["ui_text('admin.appearance.navigation.redahuvaty_punkt')",'parent_id'],
];
foreach($must as $file=>$needles){$text=@file_get_contents($root.'/'.$file);if(!is_string($text)){$fail[]='missing '.$file;continue;}foreach($needles as $n){if(!str_contains($text,$n))$fail[]=$file.' missing '.$n;}}
if($fail){fwrite(STDERR,"Builder/Navigation Check: FAILED\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Builder/Navigation Check: OK\n";
