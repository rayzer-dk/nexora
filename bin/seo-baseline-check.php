#!/usr/bin/env php
<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$required=[
 'src/Modules/Seo/Application/SeoUrlManager.php',
 'src/Modules/Seo/Application/SeoHead.php',
 'src/Modules/Seo/StructuredData/BreadcrumbListBuilder.php',
 'src/Modules/Seo/StructuredData/ProductMerchantListingBuilder.php',
 'src/Modules/Seo/StructuredData/ProductGroupStructuredDataBuilder.php',
 'src/Modules/Seo/StructuredData/OrganizationCommerceBuilder.php',
 'src/Modules/Seo/StructuredData/WebSiteBuilder.php',
 'src/Modules/Seo/Application/LocaleUrlPrefixPolicy.php',
 'src/Modules/Admin/Http/SeoRedirectAdminController.php',
 'themes/default/templates/admin/system/seo_redirects.html.twig',
];
$missing=[]; foreach($required as $f) if(!is_file($root.'/'.$f))$missing[]=$f;
$contracts=[
 ['src/Modules/Seo/Infrastructure/DbalSeoUrlRepository.php',['mc_seo_redirect','hit_count = hit_count + 1','addRedirectAlias']],
 ['src/Modules/Migration/Application/MigrationCatalogImporter.php',['seoKeywordForMappedLocale','preserveLegacyPath']],
 ['src/Modules/Admin/Http/CatalogMetadataAdminController.php',['syncBrandSeo','SeoEntityType::Brand']],
];
foreach($contracts as [$file,$needles]){$data=@file_get_contents($root.'/'.$file);if(!is_string($data)){$missing[]=$file;continue;}foreach($needles as $needle)if(!str_contains($data,$needle))$missing[]=$file.'::'.$needle;}
if($missing){fwrite(STDERR,'SEO baseline missing: '.implode(', ',$missing).PHP_EOL);exit(1);} echo 'SEO baseline gate passed: URL, canonical/hreflang, redirect history/admin and structured-data components present.'.PHP_EOL;
