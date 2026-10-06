<?php

declare(strict_types=1);

namespace Commerce\Modules\Feeds\Application;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

final readonly class CanonicalProductExportService
{
    private const BATCH_SIZE = 1000;

    public function __construct(private Connection $db, private string $publicBaseUrl) {}

    /** @return list<array<string,mixed>> */
    public function products(int $storeId,int $marketId,string $locale,string $currency,bool $inStockOnly=false): array
    {
        $rows=$this->db->fetchAllAssociative(
            "SELECT p.id,p.public_id,p.updated_at,pt.name,pt.short_description,pt.description,
                    v.id variant_id,v.public_id variant_public_id,v.sku,v.gtin,v.mpn,v.sort_order,
                    COALESCE(b.name,'') brand,COALESCE(pr.amount_minor,0) price_minor,pr.compare_at_minor,
                    COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id),0) available_quantity,
                    sr.path seo_path,
                    COALESCE(gpo.google_category_id,gcm.google_category_id) google_category_id,
                    COALESCE(gpo.product_type_path,gcm.google_category_path) product_type_path,
                    pex.custom_label_0,pex.custom_label_1,pex.custom_label_2,pex.custom_label_3,pex.custom_label_4
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active'
             JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active'
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=?
             JOIN mc_product_variant v ON v.product_id=p.id AND v.status='active'
             LEFT JOIN mc_brand b ON b.id=p.brand_id
             LEFT JOIN mc_product_extra pex ON pex.product_id=p.id
             LEFT JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority,px.id DESC LIMIT 1)
             LEFT JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id AND sr.indexable=1
             LEFT JOIN mc_google_product_override gpo ON gpo.product_id=p.id AND gpo.store_id=?
             LEFT JOIN mc_product_category pcp ON pcp.product_id=p.id AND pcp.is_primary=1
             LEFT JOIN mc_google_category_mapping gcm ON gcm.category_id=pcp.category_id
             WHERE p.status='published'
             ORDER BY p.id,v.sort_order,v.id",
            [$marketId,$storeId,$marketId,$storeId,$locale,$storeId,$marketId,$currency,$storeId,$locale,$storeId],
        );
        if ($rows === []) return [];

        $productIds=array_values(array_unique(array_map(static fn(array $r):int=>(int)$r['id'],$rows)));
        $variantIds=array_values(array_unique(array_map(static fn(array $r):int=>(int)$r['variant_id'],$rows)));
        [$productImages,$variantImages]=$this->loadImages($productIds);
        $categoryMap=$this->loadCategories($productIds,$storeId,$locale);
        [$baseAttributes,$variantAttributes]=$this->loadAttributes($productIds,$locale);

        $out=[];
        foreach($rows as $row){
            $qty=(float)$row['available_quantity']; if($inStockOnly&&$qty<=0)continue;
            $productId=(int)$row['id'];$variantId=(int)$row['variant_id'];
            $imageItems=array_merge($variantImages[$variantId]??[],$productImages[$productId]??[]);
            $images=[];$seen=[];$primaryMeta=null;
            foreach($imageItems as $image){$url=$this->absolute('/media/'.ltrim((string)$image['storage_key'],'/'));if(isset($seen[$url]))continue;$seen[$url]=true;$images[]=$url;$primaryMeta??=['mime_type'=>(string)$image['mime_type'],'width'=>$image['width']!==null?(int)$image['width']:null,'height'=>$image['height']!==null?(int)$image['height']:null];if(count($images)>=11)break;}
            if($images===[])continue;
            $productUuid=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();$variantUuid=Uuid::fromBinary((string)$row['variant_public_id'])->toRfc4122();
            $categories=$categoryMap[$productId]??[];
            $attributes=$baseAttributes[$productId]??[];foreach(($variantAttributes[$variantId]??[]) as $code=>$value)$attributes[$code]=$value;
            $description=trim(strip_tags((string)($row['description']?:$row['short_description']?:$row['name'])));$description=preg_replace('/\s+/u',' ',$description)?: (string)$row['name'];
            $path='/' . ltrim((string)$row['seo_path'],'/');$link=$this->absolute($path . ((int)$row['sort_order']>0?'?variant='.rawurlencode($variantUuid):''));
            $price=(int)$row['price_minor'];$compare=$row['compare_at_minor']!==null?(int)$row['compare_at_minor']:null;
            $out[]=[
                'id'=>$variantUuid,'item_group_id'=>$productUuid,'product_id'=>$productId,'variant_id'=>$variantId,'sku'=>(string)$row['sku'],
                'title'=>(string)$row['name'],'description'=>mb_substr($description,0,5000,'UTF-8'),'link'=>$link,'image_link'=>$images[0],'additional_image_link'=>array_slice($images,1,10),'image_meta'=>$primaryMeta,
                'availability'=>$qty>0?'in_stock':'out_of_stock','quantity'=>$qty,'price_minor'=>$price,'price'=>number_format($price/100,2,'.',''),'currency'=>strtoupper($currency),
                'sale_price'=>$compare!==null&&$compare>$price?number_format($price/100,2,'.',''):null,'regular_price'=>$compare!==null&&$compare>$price?number_format($compare/100,2,'.',''):number_format($price/100,2,'.',''),
                'brand'=>(string)$row['brand'],'gtin'=>trim((string)($row['gtin']??'')),'mpn'=>trim((string)($row['mpn']??'')),'condition'=>'new',
                'google_product_category'=>$row['google_category_id']!==null?(string)$row['google_category_id']:'','product_type'=>(string)($row['product_type_path']??''),'custom_labels'=>array_map(static fn(int $i): string=>trim((string)($row['custom_label_'.$i]??'')),range(0,4)),
                'categories'=>$categories,'attributes'=>$attributes,'updated_at'=>(string)$row['updated_at'],
            ];
        }
        return $out;
    }

    /** @return array{0:array<int,list<array<string,mixed>>>,1:array<int,list<array<string,mixed>>>} */
    private function loadImages(array $productIds): array
    {
        $product=[];$variant=[];
        foreach(array_chunk($productIds,self::BATCH_SIZE) as $ids){
            $rows=$this->db->executeQuery("SELECT pm.product_id,pm.variant_id,pm.role,pm.sort_order,ma.id asset_id,ma.storage_key,ma.mime_type,ma.width,ma.height FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id IN (?) AND pm.role IN ('primary','gallery') ORDER BY pm.product_id,(pm.variant_id IS NULL),pm.variant_id,(pm.role='primary') DESC,pm.sort_order,ma.id",[$ids],[ArrayParameterType::INTEGER])->fetchAllAssociative();
            foreach($rows as $row){if($row['variant_id']===null)$product[(int)$row['product_id']][]=$row;else $variant[(int)$row['variant_id']][]=$row;}
        }
        return [$product,$variant];
    }

    /** @return array<int,list<array{id:int,parent_id:?int,name:string,primary:bool}>> */
    private function loadCategories(array $productIds,int $storeId,string $locale): array
    {
        $map=[];
        foreach(array_chunk($productIds,self::BATCH_SIZE) as $ids){
            $rows=$this->db->executeQuery('SELECT pc.product_id,c.id,c.parent_id,ct.name,pc.is_primary FROM mc_product_category pc JOIN mc_category c ON c.id=pc.category_id JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=? WHERE pc.product_id IN (?) ORDER BY pc.product_id,pc.is_primary DESC,pc.sort_order,c.id',[$storeId,$locale,$ids],[ParameterType::INTEGER,ParameterType::STRING,ArrayParameterType::INTEGER])->fetchAllAssociative();
            foreach($rows as $r)$map[(int)$r['product_id']][]=['id'=>(int)$r['id'],'parent_id'=>$r['parent_id']!==null?(int)$r['parent_id']:null,'name'=>(string)$r['name'],'primary'=>(bool)$r['is_primary']];
        }
        return $map;
    }

    /** @return array{0:array<int,array<string,string>>,1:array<int,array<string,string>>} */
    private function loadAttributes(array $productIds,string $locale): array
    {
        $base=[];$variants=[];
        foreach(array_chunk($productIds,self::BATCH_SIZE) as $ids){
            $rows=$this->db->executeQuery("SELECT pav.product_id,pav.variant_id,ad.code,COALESCE(NULLIF(pav.value_text,''),CAST(pav.value_decimal AS CHAR),IF(pav.value_boolean IS NULL,NULL,IF(pav.value_boolean=1,'true','false'))) value FROM mc_product_attribute_value pav JOIN mc_attribute_definition ad ON ad.id=pav.attribute_id WHERE pav.product_id IN (?) AND (pav.locale IS NULL OR pav.locale=?) ORDER BY pav.product_id,pav.variant_id IS NULL DESC,ad.sort_order,pav.id",[$ids,$locale],[ArrayParameterType::INTEGER,ParameterType::STRING])->fetchAllAssociative();
            foreach($rows as $row){$value=$row['value'];if($value===null||trim((string)$value)==='')continue;if($row['variant_id']===null)$base[(int)$row['product_id']][(string)$row['code']]=(string)$value;else $variants[(int)$row['variant_id']][(string)$row['code']]=(string)$value;}
        }
        return [$base,$variants];
    }

    private function absolute(string $path):string{return rtrim($this->publicBaseUrl,'/').'/'.ltrim($path,'/');}
}
