<?php

declare(strict_types=1);

namespace Commerce\Modules\Feeds\Application;

use Doctrine\DBAL\Connection;
use DOMDocument;

final readonly class ProductFeedGenerator
{
    public function __construct(private CanonicalProductExportService $catalog, private Connection $db, private string $publicBaseUrl, private ?\Commerce\Core\Extension\ExtensionServiceRegistry $extensions = null, private ?FeedRulesService $rules = null) {}

    /** @return array<string,\Commerce\Modules\Feeds\Contract\FeedFormatProviderInterface> formats of signed modules by code; built-in codes cannot be replaced */
    public function extensionFormats(): array
    {
        $out=[];foreach($this->extensions?->all('provider.feed')??[] as $format){if($format instanceof \Commerce\Modules\Feeds\Contract\FeedFormatProviderInterface&&preg_match('/^[a-z0-9_]{2,30}$/D',$format->code())===1&&!in_array($format->code(),self::BUILT_IN,true))$out[$format->code()]=$format;}return $out;
    }

    public const BUILT_IN=['google','meta','facebook','pinterest','tiktok','rozetka','prom','csv','json','agentic'];

    /** @return array{content:string,content_type:string,extension:string,count:int,skipped:int,warnings:list<string>} */
    public function generate(string $platform,int $storeId,int $marketId,string $locale,string $currency,bool $inStockOnly=false): array
    {
        $platform=strtolower($platform);$all=$this->catalog->products($storeId,$marketId,$locale,$currency,$inStockOnly);$ruledOut=0;if($this->rules!==null){[$all,$ruledOut]=$this->rules->apply($storeId,$platform,$all);}$warnings=[];$products=[];
        foreach($all as $product){$reason=$this->validationError($platform,$product,$storeId);if($reason!==null){if(count($warnings)<50)$warnings[]=$product['sku'].': '.$reason;continue;}$products[]=$product;}
        $result=match($platform){
            'google'=>$this->googleXml($products),
            'meta','facebook'=>$this->catalogCsv($products,'meta'),
            'pinterest'=>$this->catalogCsv($products,'pinterest'),
            'tiktok'=>$this->tiktokCsv($products),
            'rozetka'=>$this->rozetkaXml($products,$storeId),
            'prom'=>$this->promYml($products,$storeId),
            'agentic'=>$this->agenticJsonl($products),
            'json'=>['content'=>json_encode(['generated_at'=>gmdate('c'),'products'=>$products],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'content_type'=>'application/json; charset=UTF-8','extension'=>'json','count'=>count($products)],
            'csv'=>$this->catalogCsv($products,'generic'),
            default=>isset($this->extensionFormats()[$platform])?$this->extensionFormat($this->extensionFormats()[$platform],$products,$storeId,$locale,$currency):throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0c8195565302')),
        };
        return $result+['skipped'=>count($all)-count($products)+$ruledOut,'warnings'=>$warnings];
    }


    /** @param list<array<string,mixed>> $products */
    private function extensionFormat(\Commerce\Modules\Feeds\Contract\FeedFormatProviderInterface $format,array $products,int $storeId,string $locale,string $currency): array
    {
        $r=$format->render($products,$storeId,$locale,$currency);
        $ext=preg_match('/^[a-z0-9]{2,8}$/D',(string)($r['extension']??''))===1?(string)$r['extension']:'txt';

        return ['content'=>(string)($r['content']??''),'content_type'=>(string)($r['content_type']??'text/plain; charset=UTF-8'),'extension'=>$ext,'count'=>count($products)];
    }

    /** @param list<array<string,mixed>> $products */
    private function agenticJsonl(array $products): array
    {
        $lines = [];
        foreach ($products as $p) {
            $lines[] = json_encode([
                'id'=>(string)$p['id'], 'group_id'=>(string)$p['item_group_id'], 'title'=>(string)$p['title'],
                'description'=>(string)$p['description'], 'url'=>(string)$p['link'], 'image'=>(string)$p['image_link'],
                'images'=>(array)$p['additional_image_link'], 'brand'=>(string)$p['brand'], 'sku'=>(string)$p['sku'],
                'gtin'=>(string)$p['gtin'], 'mpn'=>(string)$p['mpn'], 'price'=>(string)$p['price'],
                'currency'=>(string)$p['currency'], 'availability'=>(string)$p['availability'], 'quantity'=>(int)$p['quantity'],
                'categories'=>(array)$p['categories'], 'attributes'=>(array)$p['attributes'], 'product_type'=>(string)$p['product_type'],
                'generated_at'=>gmdate('c'),
            ], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        return ['content'=>implode("\n", $lines).($lines!==[]?"\n":''),'content_type'=>'application/x-ndjson; charset=UTF-8','extension'=>'jsonl','count'=>count($products)];
    }
    private function validationError(string $platform,array $p,int $storeId): ?string
    {
        foreach(['id','title','description','link','image_link','price','availability'] as $field){if(trim((string)($p[$field]??''))==='')return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.feeds.application.productfeedgenerator.vidsutnie_pole').$field;}
        if((float)$p['price']<=0)return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.feeds.application.productfeedgenerator.tsina_maie_buty_bilshoiu_za_0');
        if($platform==='tiktok'){
            if(trim((string)($p['brand']??''))==='')return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.feeds.application.productfeedgenerator.dlia_tiktok_product_catalog_potribno_pole_brand');
            $meta=(array)($p['image_meta']??[]);$mime=strtolower((string)($meta['mime_type']??''));$width=(int)($meta['width']??0);$height=(int)($meta['height']??0);
            if(!in_array($mime,['image/jpeg','image/png'],true))return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.feeds.application.productfeedgenerator.tiktok_potrebuie_osnovne_jpg_png_zobrazhennia');
            if($width>0&&$height>0&&($width<500||$height<500))return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.feeds.application.productfeedgenerator.tiktok_potrebuie_osnovne_zobrazhennia_shchonaimenshe');
        }
        if(in_array($platform,['rozetka'],true)){
            $primary=$this->primaryCategory($p);if($primary===null)return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.feeds.application.productfeedgenerator.nemaie_osnovnoi_katehorii');if($this->externalCategory($storeId,'rozetka',(int)$primary['id'])===null)return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.feeds.application.productfeedgenerator.ne_zadano_mapping_katehorii_rozetka');if(count((array)$p['attributes'])<2)return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.feeds.application.productfeedgenerator.dlia_rozetka_potribno_shchonaimenshe_2_kharakterysty');
        }
        return null;
    }

    /** @param list<array<string,mixed>> $products */
    private function catalogCsv(array $products,string $platform): array
    {
        $fp=fopen('php://temp','w+');if($fp===false)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d7538cacd338'));fwrite($fp,"\xEF\xBB\xBF");$headers=['id','item_group_id','title','description','availability','condition','price','sale_price','link','image_link','additional_image_link','brand','gtin','mpn','google_product_category','product_type'];fputcsv($fp,$headers);
        foreach($products as $p){$availability=$this->socialAvailability((string)$p['availability']);fputcsv($fp,[$p['id'],$p['item_group_id'],$p['title'],$p['description'],$availability,$p['condition'],$p['regular_price'].' '.$p['currency'],$p['sale_price']!==null?$p['sale_price'].' '.$p['currency']:'',$p['link'],$p['image_link'],implode(',',(array)$p['additional_image_link']),$p['brand'],$p['gtin'],$p['mpn'],$p['google_product_category'],$p['product_type']]);}
        rewind($fp);$content=stream_get_contents($fp);fclose($fp);return ['content'=>(string)$content,'content_type'=>'text/csv; charset=UTF-8','extension'=>'csv','count'=>count($products)];
    }

    /** @param list<array<string,mixed>> $products */
    private function tiktokCsv(array $products): array
    {
        $fp=fopen('php://temp','w+');if($fp===false)throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d7538cacd338'));fwrite($fp,"\xEF\xBB\xBF");$headers=['sku_id','title','description','availability','condition','price','link','image_link','brand','google_product_category','additional_image_link','item_group_id','product_type','sale_price','gtin','mpn'];fputcsv($fp,$headers);
        foreach($products as $p){fputcsv($fp,[$p['id'],$p['title'],$p['description'],$this->socialAvailability((string)$p['availability']),$p['condition'],$p['regular_price'].' '.$p['currency'],$p['link'],$p['image_link'],$p['brand'],$p['google_product_category'],implode(',',(array)$p['additional_image_link']),$p['item_group_id'],$p['product_type'],$p['sale_price']!==null?$p['sale_price'].' '.$p['currency']:'',$p['gtin'],$p['mpn']]);}
        rewind($fp);$content=stream_get_contents($fp);fclose($fp);return ['content'=>(string)$content,'content_type'=>'text/csv; charset=UTF-8','extension'=>'csv','count'=>count($products)];
    }

    private function socialAvailability(string $availability): string
    {
        return match($availability){'in_stock'=>'in stock','out_of_stock'=>'out of stock','backorder'=>'available for order','preorder'=>'preorder',default=>'out of stock'};
    }

    /** @param list<array<string,mixed>> $products */
    private function googleXml(array $products): array
    {
        $xml=new DOMDocument('1.0','UTF-8');$xml->formatOutput=true;$rss=$xml->createElement('rss');$rss->setAttribute('version','2.0');$rss->setAttribute('xmlns:g','http://base.google.com/ns/1.0');$xml->appendChild($rss);$channel=$xml->createElement('channel');$rss->appendChild($channel);$this->el($xml,$channel,'title','Product Feed');$this->el($xml,$channel,'link',rtrim($this->publicBaseUrl,'/').'/');$this->el($xml,$channel,'description','Nexora Commerce product feed');
        foreach($products as $p){$item=$xml->createElement('item');$channel->appendChild($item);$this->el($xml,$item,'g:id',(string)$p['id']);$this->el($xml,$item,'g:item_group_id',(string)$p['item_group_id']);$this->el($xml,$item,'g:title',(string)$p['title']);$this->el($xml,$item,'g:description',(string)$p['description']);$this->el($xml,$item,'g:link',(string)$p['link']);$this->el($xml,$item,'g:image_link',(string)$p['image_link']);foreach((array)$p['additional_image_link'] as $image)$this->el($xml,$item,'g:additional_image_link',(string)$image);$this->el($xml,$item,'g:availability',(string)$p['availability']);$this->el($xml,$item,'g:condition','new');$this->el($xml,$item,'g:price',$p['regular_price'].' '.$p['currency']);if($p['sale_price']!==null)$this->el($xml,$item,'g:sale_price',$p['sale_price'].' '.$p['currency']);foreach(['brand','gtin','mpn','google_product_category','product_type'] as $k)if((string)$p[$k]!=='')$this->el($xml,$item,'g:'.$k,(string)$p[$k]);}
        return ['content'=>$xml->saveXML()?:'','content_type'=>'application/xml; charset=UTF-8','extension'=>'xml','count'=>count($products)];
    }

    /** @param list<array<string,mixed>> $products */
    private function rozetkaXml(array $products,int $storeId): array
    {
        $xml=new DOMDocument('1.0','UTF-8');$xml->formatOutput=true;$root=$xml->createElement('price');$xml->appendChild($root);$this->el($xml,$root,'date',gmdate('Y-m-d H:i'));$catalog=$xml->createElement('catalog');$root->appendChild($catalog);$seen=[];
        foreach($products as $p){$primary=$this->primaryCategory($p);if($primary===null)continue;$map=$this->externalCategory($storeId,'rozetka',(int)$primary['id']);if($map===null)continue;$key=$map['external_category_id'];if(isset($seen[$key]))continue;$seen[$key]=true;$c=$xml->createElement('category');$c->setAttribute('id',$key);$c->appendChild($xml->createTextNode((string)($map['external_category_name']?:$primary['name'])));$catalog->appendChild($c);}
        $items=$xml->createElement('items');$root->appendChild($items);foreach($products as $p){$primary=$this->primaryCategory($p);$map=$primary!==null?$this->externalCategory($storeId,'rozetka',(int)$primary['id']):null;if($map===null)continue;$i=$xml->createElement('item');$i->setAttribute('id',(string)$p['id']);if($p['item_group_id']!==$p['id'])$i->setAttribute('group_id',(string)$p['item_group_id']);$items->appendChild($i);$this->el($xml,$i,'name',(string)$p['title']);$this->el($xml,$i,'categoryId',(string)$map['external_category_id']);$this->el($xml,$i,'price',(string)$p['price']);if($p['sale_price']!==null)$this->el($xml,$i,'oldprice',(string)$p['regular_price']);$this->el($xml,$i,'currencyId',(string)$p['currency']);$this->el($xml,$i,'stock_quantity',(string)$p['quantity']);foreach(array_merge([(string)$p['image_link']],(array)$p['additional_image_link']) as $image)$this->el($xml,$i,'image',$image);if((string)$p['brand']!=='')$this->el($xml,$i,'vendor',(string)$p['brand']);$this->el($xml,$i,'vendorCode',(string)$p['sku']);$this->el($xml,$i,'description',(string)$p['description']);if((string)$p['gtin']!=='')$this->el($xml,$i,'barcode',(string)$p['gtin']);foreach((array)$p['attributes'] as $name=>$value){$param=$xml->createElement('param');$param->setAttribute('name',(string)$name);$param->appendChild($xml->createTextNode((string)$value));$i->appendChild($param);}}
        return ['content'=>$xml->saveXML()?:'','content_type'=>'application/xml; charset=UTF-8','extension'=>'xml','count'=>count($products)];
    }

    /** @param list<array<string,mixed>> $products */
    private function promYml(array $products,int $storeId): array
    {
        $xml=new DOMDocument('1.0','UTF-8');$xml->formatOutput=true;$yml=$xml->createElement('yml_catalog');$yml->setAttribute('date',gmdate('Y-m-d H:i'));$xml->appendChild($yml);$shop=$xml->createElement('shop');$yml->appendChild($shop);$categories=$xml->createElement('categories');$shop->appendChild($categories);$seen=[];
        foreach($products as $p){foreach((array)$p['categories'] as $cat){$id=(int)$cat['id'];if(isset($seen[$id]))continue;$seen[$id]=true;$node=$xml->createElement('category');$node->setAttribute('id',(string)$id);if($cat['parent_id']!==null)$node->setAttribute('parentId',(string)$cat['parent_id']);$map=$this->externalCategory($storeId,'prom',$id);if($map!==null)$node->setAttribute('portal_id',(string)$map['external_category_id']);$node->appendChild($xml->createTextNode((string)$cat['name']));$categories->appendChild($node);}}
        $offers=$xml->createElement('offers');$shop->appendChild($offers);foreach($products as $p){$primary=$this->primaryCategory($p);if($primary===null)continue;$offer=$xml->createElement('offer');$offer->setAttribute('id',(string)$p['id']);$offer->setAttribute('available',$p['availability']==='in_stock'?'true':'false');$offer->setAttribute('in_stock',$p['availability']==='in_stock'?'true':'false');if($p['item_group_id']!==$p['id'])$offer->setAttribute('group_id',(string)$p['item_group_id']);$offers->appendChild($offer);$this->el($xml,$offer,'name',(string)$p['title']);$this->el($xml,$offer,'categoryId',(string)$primary['id']);$this->el($xml,$offer,'url',(string)$p['link']);$this->el($xml,$offer,'price',(string)$p['price']);if($p['sale_price']!==null)$this->el($xml,$offer,'oldprice',(string)$p['regular_price']);$this->el($xml,$offer,'currencyId',(string)$p['currency']);foreach(array_merge([(string)$p['image_link']],(array)$p['additional_image_link']) as $image)$this->el($xml,$offer,'picture',$image);if((string)$p['brand']!=='')$this->el($xml,$offer,'vendor',(string)$p['brand']);$this->el($xml,$offer,'vendorCode',(string)$p['sku']);$this->el($xml,$offer,'description',(string)$p['description']);if((string)$p['gtin']!=='')$this->el($xml,$offer,'gtin',(string)$p['gtin']);if((string)$p['mpn']!=='')$this->el($xml,$offer,'mpn',(string)$p['mpn']);foreach((array)$p['attributes'] as $name=>$value){$param=$xml->createElement('param');$param->setAttribute('name',(string)$name);$param->appendChild($xml->createTextNode((string)$value));$offer->appendChild($param);}}
        return ['content'=>$xml->saveXML()?:'','content_type'=>'application/xml; charset=UTF-8','extension'=>'xml','count'=>count($products)];
    }

    private function externalCategory(int $storeId,string $platform,int $categoryId): ?array
    {
        $row=$this->db->fetchAssociative('SELECT external_category_id,external_category_name FROM mc_feed_category_mapping WHERE store_id=? AND platform=? AND category_id=? LIMIT 1',[$storeId,$platform,$categoryId]);return is_array($row)?$row:null;
    }
    private function primaryCategory(array $product): ?array{foreach((array)$product['categories'] as $category)if(!empty($category['primary']))return $category;return $product['categories'][0]??null;}
    private function el(DOMDocument $xml,\DOMElement $parent,string $name,string $value):void{$node=str_starts_with($name,'g:')?$xml->createElementNS('http://base.google.com/ns/1.0',$name):$xml->createElement($name);$node->appendChild($xml->createTextNode($value));$parent->appendChild($node);}
}
