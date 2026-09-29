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
            'promos'=>$this->promos($context),
            'counts'=>[
                'products'=>(int)$this->connection->fetchOne("SELECT COUNT(*) FROM mc_product WHERE status='published'"),
                'categories'=>(int)$this->connection->fetchOne("SELECT COUNT(*) FROM mc_store_category WHERE store_id=? AND status='active'",[$context->storeId]),
            ],
            'brands'=>array_values(array_map(static fn(mixed $name):string=>(string)$name,$brands)),
            'articles'=>$this->blog->latest($context->storeId,$context->locale,3),
            'coupon'=>[
                'code'=>'DEMO10',
                'text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.10_na_demo_zamovlennia_vid_2_000'),
            ],
        ];
    }

    /**
     * Showcase banners are built from the store's real categories and their product photos,
     * so every banner links to an existing page and never depends on baked-in artwork.
     *
     * @return list<array{eyebrow:string,title:string,text:string,url:string,image:string,tone:string}>
     */
    private function promos(StorefrontContext $context): array
    {
        $rows=$this->connection->fetchAllAssociative(
            "SELECT ct.name,ct.description,sr.path,
                    (SELECT ma.storage_key FROM mc_product_category pcx JOIN mc_product px ON px.id=pcx.product_id AND px.status='published' JOIN mc_product_media pm ON pm.product_id=px.id AND pm.role IN ('primary','gallery') JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pcx.category_id=c.id ORDER BY (pm.role='primary') DESC,pm.sort_order ASC,px.id ASC LIMIT 1) AS image_key
             FROM mc_category c
             JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active'
             JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=?
             JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='category' AND sr.entity_public_id=c.public_id
             WHERE c.status='active' AND c.parent_id IS NULL
             ORDER BY sc.sort_order ASC,c.sort_order ASC,c.id ASC LIMIT 3",
            [$context->storeId,$context->storeId,$context->locale,$context->storeId,$context->locale],
        );
        $tones=['blue','violet','graphite'];
        $promos=[];
        foreach($rows as $i=>$row){
            if(!is_string($row['image_key']??null)||$row['image_key']===''){continue;}
            $promos[]=[
                'eyebrow'=>'',
                'title'=>(string)$row['name'],
                'text'=>(string)($row['description']??''),
                'url'=>'/'.ltrim((string)$row['path'],'/'),
                'image'=>'/media/'.ltrim((string)$row['image_key'],'/'),
                'tone'=>$tones[$i%3],
            ];
        }
        return $promos;
    }
}
