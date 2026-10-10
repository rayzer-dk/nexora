<?php

declare(strict_types=1);

namespace Commerce\Modules\ImportExport\Application;

use Commerce\Modules\Catalog\Application\Command\CreateProductCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Catalog\Application\ProductEditQueryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogCsvService
{
    private const MAX_IMPORT_ROWS=10000;
    public function __construct(private Connection $db,private ProductWriter $writer,private ProductEditQueryInterface $query){}

    public function exportProducts(int $storeId,int $marketId,string $locale): string
    {
        $rows=$this->db->fetchAllAssociative("SELECT p.id,p.public_id,p.status,p.product_type,p.brand_id,b.name brand_name,pt.name,pt.short_description,pt.description,v.sku,v.gtin,v.mpn,v.sale_unit_code,pr.amount_minor,pr.currency,COALESCE((SELECT sl.stocked_quantity FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id ORDER BY mil.priority,sl.location_id LIMIT 1),'0.000000') stock_quantity,GROUP_CONCAT(pc.category_id ORDER BY pc.is_primary DESC,pc.sort_order,pc.category_id SEPARATOR '|') category_ids,sr.slug,sr.path,pt.h1,pt.meta_title,pt.meta_description,(SELECT GROUP_CONCAT(ma.storage_key ORDER BY (pm.role='primary') DESC,pm.sort_order SEPARATOR '|') FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=p.id AND pm.role IN ('primary','gallery')) image_keys,(SELECT GROUP_CONCAT(ct.name ORDER BY pc2.is_primary DESC,pc2.sort_order SEPARATOR ' | ') FROM mc_product_category pc2 JOIN mc_category_translation ct ON ct.category_id=pc2.category_id AND ct.store_id=? AND ct.locale=? WHERE pc2.product_id=p.id) category_names FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=? JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0 LEFT JOIN mc_brand b ON b.id=p.brand_id LEFT JOIN mc_price pr ON pr.id=(SELECT p2.id FROM mc_price p2 WHERE p2.variant_id=v.id AND p2.store_id=? AND p2.market_id=? AND p2.customer_group='default' AND p2.price_list_id IS NULL AND p2.max_quantity IS NULL AND p2.starts_at IS NULL AND p2.ends_at IS NULL ORDER BY p2.priority,p2.id DESC LIMIT 1) LEFT JOIN mc_product_category pc ON pc.product_id=p.id LEFT JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id GROUP BY p.id,p.public_id,p.status,p.product_type,p.brand_id,b.name,pt.name,pt.short_description,pt.description,v.sku,v.gtin,v.mpn,v.sale_unit_code,pr.amount_minor,pr.currency,sr.slug,sr.path,pt.h1,pt.meta_title,pt.meta_description ORDER BY p.id",[$marketId,$storeId,$locale,$storeId,$storeId,$locale,$storeId,$marketId,$storeId,$locale]);
        $fp=fopen('php://temp','w+'); if($fp===false)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f354cf4cb532'));
        fwrite($fp,"\xEF\xBB\xBF"); fputcsv($fp,['public_id','sku','name','status','product_type','price','currency','stock_quantity','unit_code','gtin','mpn','brand_id','brand','category_ids','category_names','slug','url','short_description','description','h1','meta_title','meta_description','image','images'],',','"','');
        foreach($rows as $r){fputcsv($fp,[Uuid::fromBinary((string)$r['public_id'])->toRfc4122(),$r['sku'],$r['name'],$r['status'],$r['product_type'],number_format(((int)($r['amount_minor']??0))/100,2,'.',''),$r['currency']??$this->marketCurrency($storeId,$marketId),$r['stock_quantity'],$r['sale_unit_code'],$r['gtin'],$r['mpn'],$r['brand_id'],$r['brand_name'],$r['category_ids'],$r['category_names'],$r['slug'],$r['path']!==null&&$r['path']!==''?'/'.ltrim((string)$r['path'],'/'):'',$r['short_description'],$r['description'],$r['h1'],$r['meta_title'],$r['meta_description'],$this->exportImages((string)($r['image_keys']??''),false),$this->exportImages((string)($r['image_keys']??''),true)],',','"','');}
        rewind($fp); $csv=stream_get_contents($fp); fclose($fp); return is_string($csv)?$csv:'';
    }

    public function exportProductsMultilingual(int $storeId,int $marketId): string
    {
        $locales=array_values(array_map('strval',$this->db->fetchFirstColumn('SELECT locale_code FROM mc_store_locale WHERE store_id=? AND enabled=1 ORDER BY is_default DESC,sort_order,locale_code',[$storeId])));
        if($locales===[])throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.76287ce9b814'));
        $rows=$this->db->fetchAllAssociative("SELECT p.id,p.public_id,p.status,p.product_type,p.brand_id,b.name brand_name,v.sku,v.gtin,v.mpn,v.sale_unit_code,pr.amount_minor,pr.currency,COALESCE((SELECT sl.stocked_quantity FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id ORDER BY mil.priority,sl.location_id LIMIT 1),'0.000000') stock_quantity,GROUP_CONCAT(pc.category_id ORDER BY pc.is_primary DESC,pc.sort_order,pc.category_id SEPARATOR '|') category_ids FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0 LEFT JOIN mc_brand b ON b.id=p.brand_id LEFT JOIN mc_price pr ON pr.id=(SELECT p2.id FROM mc_price p2 WHERE p2.variant_id=v.id AND p2.store_id=? AND p2.market_id=? AND p2.customer_group='default' AND p2.price_list_id IS NULL AND p2.max_quantity IS NULL AND p2.starts_at IS NULL AND p2.ends_at IS NULL ORDER BY p2.priority,p2.id DESC LIMIT 1) LEFT JOIN mc_product_category pc ON pc.product_id=p.id GROUP BY p.id,p.public_id,p.status,p.product_type,p.brand_id,b.name,v.sku,v.gtin,v.mpn,v.sale_unit_code,pr.amount_minor,pr.currency ORDER BY p.id",[$marketId,$storeId,$storeId,$marketId]);
        $translations=$this->db->fetchAllAssociative('SELECT pt.product_id,pt.locale,pt.name,pt.short_description,pt.description,pt.meta_title,pt.meta_description,COALESCE(sr.slug,pt.slug) slug FROM mc_product_translation pt LEFT JOIN mc_seo_route sr ON sr.store_id=pt.store_id AND sr.locale=pt.locale AND sr.entity_type=\'product\' AND sr.entity_public_id=(SELECT p.public_id FROM mc_product p WHERE p.id=pt.product_id) WHERE pt.store_id=?',[$storeId]);
        $by=[];foreach($translations as $t)$by[(int)$t['product_id']][(string)$t['locale']]=$t;
        $base=['public_id','sku','status','product_type','price','currency','stock_quantity','unit_code','gtin','mpn','brand_id','brand','category_ids'];
        $localized=[];foreach($locales as $loc)foreach(['name','slug','short_description','description','meta_title','meta_description'] as $field)$localized[]=$field.'['.$loc.']';
        $fp=fopen('php://temp','w+');if($fp===false)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f354cf4cb532'));fwrite($fp,"\xEF\xBB\xBF");fputcsv($fp,array_merge($base,$localized),',','"','');
        foreach($rows as $r){$line=[Uuid::fromBinary((string)$r['public_id'])->toRfc4122(),$r['sku'],$r['status'],$r['product_type'],number_format(((int)($r['amount_minor']??0))/100,2,'.',''),$r['currency']??$this->marketCurrency($storeId,$marketId),$r['stock_quantity'],$r['sale_unit_code'],$r['gtin'],$r['mpn'],$r['brand_id'],$r['brand_name'],$r['category_ids']];foreach($locales as $loc){$t=$by[(int)$r['id']][$loc]??[];foreach(['name','slug','short_description','description','meta_title','meta_description'] as $field)$line[]=(string)($t[$field]??'');}fputcsv($fp,$line,',','"','');}
        rewind($fp);$csv=stream_get_contents($fp);fclose($fp);return is_string($csv)?$csv:'';
    }

    /** @return array{rows:int,valid:int,invalid:int,errors:list<string>} */
    public function preview(string $path,int $storeId=0,int $marketId=0,string $locale=''): array { return $this->process($path,$storeId,$marketId,$locale,false); }

    /** @return array{rows:int,created:int,updated:int,failed:int,errors:list<string>} */
    public function import(string $path,int $storeId,int $marketId,string $locale): array { return $this->process($path,$storeId,$marketId,$locale,true); }

    /** @return array<string,mixed> */
    private function process(string $path,int $storeId,int $marketId,string $locale,bool $apply): array
    {
        $fh=fopen($path,'rb'); if($fh===false)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.88fc5cabb738'));
        $header=fgetcsv($fh,0,',','"',''); if(!is_array($header)){fclose($fh);throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2d6a7812a1a6'));}
        $header=array_map(static fn($v)=>mb_strtolower(trim((string)$v," \t\n\r\0\x0B\xEF\xBB\xBF")),$header);
        foreach(['sku','name','price'] as $required){if(!in_array($required,$header,true)){fclose($fh);throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5b6831084ba2').$required);}}
        $stats=$apply?['rows'=>0,'created'=>0,'updated'=>0,'failed'=>0,'errors'=>[]]:['rows'=>0,'valid'=>0,'invalid'=>0,'errors'=>[]];
        $line=1;
        while(($raw=fgetcsv($fh,0,',','"',''))!==false){$line++; if(++$stats['rows']>self::MAX_IMPORT_ROWS){$stats['errors'][]=\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.perevyshcheno_limit').self::MAX_IMPORT_ROWS.\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.riadkiv');break;} $row=[]; foreach($header as $i=>$key)$row[$key]=(string)($raw[$i]??'');
            try{if(trim((string)($row['currency']??''))===''){$row['currency']=$this->marketCurrency($storeId,$marketId);} $data=$this->normalize($row); if(!$apply){$stats['valid']++;continue;} $existing=$this->db->fetchAssociative('SELECT p.public_id FROM mc_product_variant v JOIN mc_product p ON p.id=v.product_id JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE v.sku=? LIMIT 1',[$storeId,$data['sku']]);
                if(is_array($existing)){$publicId=Uuid::fromBinary((string)$existing['public_id'])->toRfc4122();$current=$this->query->productForEdit($storeId,$marketId,$locale,$publicId);$this->writer->update(new UpdateProductCommand((int)$current['id'],$storeId,$marketId,$locale,$data['name'],$data['sku'],$data['price_minor'],$data['currency'],$data['stock_quantity'],$data['unit_code'],$data['category_ids'],(string)($current['slug']??''),$data['short_description'],$data['description']??($current['description']!==null?(string)$current['description']:null),$data['gtin'],$data['mpn'],$data['status'],$data['brand_id']??($current['brand_id']!==null?(int)$current['brand_id']:null)));$stats['updated']++;}
                else{$this->writer->create(new CreateProductCommand($storeId,$marketId,$locale,$data['name'],$data['sku'],$data['price_minor'],$data['currency'],$data['stock_quantity'],$data['unit_code'],$data['product_type'],$data['category_ids'],$data['slug'],$data['short_description'],$data['description'],$data['gtin'],$data['mpn'],$data['brand_id']));$stats['created']++;}
                $this->applyMeasures($data);
            }catch(\Throwable $e){if($apply)$stats['failed']++;else $stats['invalid']++;if(count($stats['errors'])<50)$stats['errors'][]=\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.riadok').$line.': '.$e->getMessage();}
        }
        fclose($fh); return $stats;
    }

    /** Weight (kg) and size (mm) from the file are set on the variant that carries the SKU; empty cells leave the current values alone. @param array<string,mixed> $data */
    private function applyMeasures(array $data): void
    {
        $set = [];
        foreach (['weight_kg' => 3, 'length_mm' => 0, 'width_mm' => 0, 'height_mm' => 0] as $key => $decimals) {
            if (($data[$key] ?? null) !== null && (float) $data[$key] >= 0) {
                $set[$key] = $decimals === 0 ? (int) round((float) $data[$key]) : round((float) $data[$key], $decimals);
            }
        }
        if ($set !== []) {
            $this->db->update('mc_product_variant', $set, ['sku' => (string) $data['sku']]);
        }
    }

    /** Photo addresses for the export: the main photo, or all photos separated by "|" (relative to the site). */
    private function exportImages(string $keys, bool $all): string
    {
        $list = array_values(array_filter(explode('|', $keys), static fn (string $k): bool => $k !== '' && !str_contains($k, '..')));
        $list = array_map(static fn (string $k): string => '/media/' . ltrim($k, '/'), $list);

        return $all ? implode('|', $list) : ($list[0] ?? '');
    }

    private function marketCurrency(int $storeId,int $marketId): string
    {
        if($storeId<1||$marketId<1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.dlia_csv_bez_kolonky_currency_potriben_aktyvnyi_maha'));
        $currency=strtoupper((string)$this->db->fetchOne('SELECT default_currency FROM mc_market WHERE id=? AND store_id=? LIMIT 1',[$marketId,$storeId]));
        if(preg_match('/^[A-Z]{3}$/D',$currency)!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d38a57a84fe3'));
        return $currency;
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        $sku=trim($row['sku']??'');$name=trim($row['name']??'');$price=trim($row['price']??'');
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\\/-]{0,189}$/',$sku))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektnyi_sku'));
        if($name===''||mb_strlen($name)>255)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektna_nazva'));
        if(!preg_match('/^\d{1,12}(?:[.,]\d{1,2})?$/',$price))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektna_tsina'));
        $minor=(int)round(((float)str_replace(',','.',$price))*100);$currency=mb_strtoupper(trim($row['currency']??''));if(!preg_match('/^[A-Z]{3}$/',$currency))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektna_valiuta'));
        $stock=trim($row['stock_quantity']??'0');if(!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/',$stock))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektnyi_zalyshok'));
        $cats=array_values(array_filter(array_map('intval',preg_split('/[|,;]/',$row['category_ids']??'',-1,PREG_SPLIT_NO_EMPTY)?:[]),static fn(int $v)=>$v>0));
        $status=trim($row['status']??'draft');if(!in_array($status,['draft','published','archived'],true))$status='draft';$type=trim($row['product_type']??'physical');if(!in_array($type,['physical','digital'],true))$type='physical';
        $brandId=(int)($row['brand_id']??0);if($brandId<1){$brandId=null;$brandName=trim((string)($row['brand']??''));if($brandName!==''){$found=$this->db->fetchOne('SELECT id FROM mc_brand WHERE name=? LIMIT 1',[$brandName]);if($found!==false)$brandId=(int)$found;}}
        $slug=trim($row['slug']??''); if($slug!==''&&!preg_match('/^[\pL\pN][\pL\pN._~-]*$/u',$slug))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektnyi_seo_slug'));
        $measure=static fn(string $k):?string=>(isset($row[$k])&&trim((string)$row[$k])!==''&&is_numeric(str_replace(',','.',trim((string)$row[$k]))))?str_replace(',','.',trim((string)$row[$k])):null; return ['weight_kg'=>$measure('weight_kg'),'length_mm'=>$measure('length_mm'),'width_mm'=>$measure('width_mm'),'height_mm'=>$measure('height_mm'),'sku'=>$sku,'name'=>$name,'price_minor'=>$minor,'currency'=>$currency,'stock_quantity'=>$stock,'unit_code'=>trim($row['unit_code']??'item')?:'item','category_ids'=>$cats,'status'=>$status,'product_type'=>$type,'gtin'=>trim($row['gtin']??'')?:null,'mpn'=>trim($row['mpn']??'')?:null,'brand_id'=>$brandId,'slug'=>$slug!==''?$slug:null,'short_description'=>trim($row['short_description']??'')?:null,'description'=>trim($row['description']??'')?:null];
    }
}
