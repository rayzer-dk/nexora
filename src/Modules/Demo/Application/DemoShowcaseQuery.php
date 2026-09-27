<?php

declare(strict_types=1);

namespace Commerce\Modules\Demo\Application;

use Commerce\Modules\Content\Infrastructure\DbalBlogQuery;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Doctrine\DBAL\Connection;

final readonly class DemoShowcaseQuery
{
    public function __construct(private Connection $connection, private DbalBlogQuery $blog){}

    /** @return array<string,mixed>|null */
    public function homepage(StorefrontContext $context): ?array
    {
        $storePublic=$this->connection->fetchOne('SELECT public_id FROM mc_store WHERE id=?',[$context->storeId]);
        if(!is_string($storePublic)){return null;}
        $installed=(int)$this->connection->fetchOne("SELECT COUNT(*) FROM mc_entity_metadata WHERE entity_type='store' AND entity_public_id=? AND namespace=? AND meta_key='installed'",[$storePublic,'demo-'.$context->storeId])>0;
        if(!$installed){return null;}

        $brands=$this->connection->fetchFirstColumn(
            "SELECT b.name FROM mc_brand b JOIN mc_store_brand sb ON sb.brand_id=b.id WHERE sb.store_id=? AND sb.status='active' ORDER BY sb.sort_order,b.name LIMIT 12",
            [$context->storeId],
        );

        return [
            'announcement'=>[
                'title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.demo_vitryna_modern_commerce'),
                'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.vesniani_propozytsii_do_25_bezkoshtovna_dostavka_vid'),
                'action_label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.perehlianuty_aktsii'),
                'action_url'=>'/catalog',
            ],
            'benefits'=>[
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.shvydka_dostavka'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.po_vsii_ukraini'),'icon'=>'truck'],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.ofitsiina_harantiia'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.prozori_umovy'),'icon'=>'shield'],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.14_dniv_na_povernennia'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.zruchnyi_protses'),'icon'=>'box'],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.bezpechna_oplata'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.zakhyshchenyi_checkout'),'icon'=>'card'],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.realni_vidhuky'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.reitynh_tovariv'),'icon'=>'star'],
                ['title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.pidtrymka'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.formy_ta_kontakty'),'icon'=>'headset'],
            ],
            'category_tiles'=>[
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.smartfony'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.smartfony_ta_hadzhety'),'url'=>'/smartphones','image'=>'/media/demo/smartphone-neo-x1.webp'],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.noutbuky'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.robota_ta_navchannia'),'url'=>'/laptops','image'=>'/media/demo/laptop-pro-14.webp'],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.pobutova_tekhnika'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.tekhnika_dlia_domu'),'url'=>'/home-appliances','image'=>'/media/demo/coffee-machine-barista.webp'],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.dim_i_interier'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.zatyshnyi_prostir'),'url'=>'/home-interior','image'=>'/media/demo/category-home-interior.webp'],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.krasa_i_zdorovia'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.dohliad_shchodnia'),'url'=>'/beauty-health','image'=>'/media/demo/category-beauty.webp'],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.sport_i_vidpochynok'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.aktyvnyi_styl'),'url'=>'/sport-leisure','image'=>'/media/demo/category-sport.webp'],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.dytiachi_tovary'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.dlia_ditei_ta_batkiv'),'url'=>'/kids','image'=>'/media/demo/category-kids.webp'],
                ['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.aksesuary'),'subtitle'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.korysni_dopovnennia'),'url'=>'/accessories','image'=>'/media/demo/headphones-airbeat.webp'],
            ],
            'promos'=>[
                ['eyebrow'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.dlia_domu'),'title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.komfort_pochynaietsia_z_detalei'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.kavomashyny_tekhnika_ta_rishennia_dlia_zatyshku'),'url'=>'/home-appliances','image'=>'/media/demo/hero-coffee-reference.webp','tone'=>'blue'],
                ['eyebrow'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.novynky'),'title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.smartfony_dlia_shchodennykh_zadach'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.yaskravyi_ekran_kamera_ta_shvydka_robota'),'url'=>'/smartphones','image'=>'/media/demo/promo-smartphone-reference.webp','tone'=>'orange'],
                ['eyebrow'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.krasa_i_dohliad'),'title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.turbota_shcho_nadykhaie'),'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.shchodennyi_dohliad_ta_korysni_nabory'),'url'=>'/beauty-health','image'=>'/media/demo/promo-beauty-reference.webp','tone'=>'green'],
            ],
            'brands'=>array_values(array_map(static fn(mixed $name):string=>(string)$name,$brands)),
            'articles'=>$this->blog->latest($context->storeId,$context->locale,3),
            'coupon'=>[
                'code'=>'DEMO10',
                'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.10_na_demo_zamovlennia_vid_2_000'),
            ],
        ];
    }
}
