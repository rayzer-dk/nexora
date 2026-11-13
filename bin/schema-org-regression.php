#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/I18n/CanonicalUiText.php';
require_once dirname(__DIR__) . '/src/Modules/Seo/StructuredData/ContentSchemaType.php';
require_once dirname(__DIR__) . '/src/Modules/Seo/StructuredData/StructuredDataGraphBuilder.php';
require_once dirname(__DIR__) . '/src/Modules/Seo/StructuredData/ArticleStructuredDataBuilder.php';
require_once dirname(__DIR__) . '/src/Modules/Seo/StructuredData/OrganizationCommerceBuilder.php';
require_once dirname(__DIR__) . '/src/Modules/Shipping/Application/ShippingCountryService.php';
require_once dirname(__DIR__) . '/src/Modules/Seo/Application/MerchantSchemaSettings.php';

use Commerce\Modules\Seo\StructuredData\ContentSchemaType;
use Commerce\Modules\Seo\StructuredData\StructuredDataGraphBuilder;
use Commerce\Modules\Seo\StructuredData\ArticleStructuredDataBuilder;
use Commerce\Modules\Seo\StructuredData\OrganizationCommerceBuilder;
use Commerce\Modules\Seo\Application\MerchantSchemaSettings;

function ensure(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "Schema.org regression failed: {$message}\n");
        exit(1);
    }
}

ensure(ContentSchemaType::page('auto','about') === 'AboutPage', 'About defaults to AboutPage');
ensure(ContentSchemaType::page('auto','contacts') === 'ContactPage', 'Contacts defaults to ContactPage');
ensure(ContentSchemaType::page('auto','faq') === 'FAQPage', 'FAQ defaults to FAQPage');
ensure(ContentSchemaType::page('auto','delivery') === 'WebPage', 'Shipping info is WebPage');
ensure(ContentSchemaType::page('none','about') === null, 'No per-page schema');
ensure(ContentSchemaType::article('auto') === 'BlogPosting', 'BlogPosting default');
ensure(ContentSchemaType::article('NewsArticle') === 'NewsArticle', 'NewsArticle override');
ensure(ContentSchemaType::article('none') === null, 'No per-article schema');
try {
    ContentSchemaType::validate('Product');
    ensure(false,'Products cannot be selected on information pages');
} catch (InvalidArgumentException) {
}
$node=ContentSchemaType::pageNode('auto','about','About','https://example.test/about','uk-UA','','https://example.test/#organization');
ensure($node['about']['@id'] === 'https://example.test/#organization', 'About links to merchant identity');
$org=(new OrganizationCommerceBuilder())->build(['name'=>'Example','url'=>'https://example.test/']);
ensure($org['@type'] === 'OnlineStore', 'Merchant type is OnlineStore');
$graph=(new StructuredDataGraphBuilder())->build(
    $org,
    $org,
    ['@type'=>'WebSite','@id'=>'https://example.test/#website','url'=>'https://example.test/']
);
ensure(count($graph['@graph'])===2, 'Duplicate nodes are not emitted');
$article=(new ArticleStructuredDataBuilder())->build(['title'=>'A','url'=>'https://example.test/a','published_at'=>'2026-11-13','schema_type'=>'NewsArticle']);
ensure($article['@type']==='NewsArticle', 'Explicit blog type is used');

$merchant=(new ReflectionClass(MerchantSchemaSettings::class))->newInstanceWithoutConstructor();
$default=$merchant->normalize(MerchantSchemaSettings::defaults());
ensure($default['shipping_enabled']===false && $default['returns_enabled']===false && $default['enabled']===true,
    'Policies off by default without disabling site schema');
$valid=$merchant->normalize(array_replace(MerchantSchemaSettings::defaults(),[
    'shipping_enabled'=>true,'shipping_countries'=>'ua, pl','shipping_currency'=>'uah',
    'shipping_rate'=>'0','handling_min'=>'0','handling_max'=>'2','transit_min'=>'1','transit_max'=>'4',
    'returns_enabled'=>true,'return_countries'=>'ua','return_category'=>'finite','return_days'=>'14',
]));
ensure($valid['shipping_currency']==='UAH' && $valid['shipping_countries']==='UA, PL', 'Country and currency normalized');
foreach ([['shipping_rate'=>'-5'],['shipping_rate'=>''],['transit_min'=>'9','transit_max'=>'1']] as $bad) {
    try {
        $merchant->normalize(array_replace($valid,$bad));
        ensure(false,'Invalid shipping accepted');
    } catch (InvalidArgumentException) {
    }
}
try {
    $merchant->normalize(array_replace(MerchantSchemaSettings::defaults(),['shipping_on_offers'=>true]));
    ensure(false,'Product offer shipping enabled without general shipping');
} catch (InvalidArgumentException) {
}
echo "Schema.org type, organization and merchant policy regression passed.\n";
