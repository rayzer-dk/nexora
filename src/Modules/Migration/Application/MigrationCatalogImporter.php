<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Catalog\Application\CategoryWriter;
use Commerce\Modules\Catalog\Application\Command\CreateCategoryCommand;
use Commerce\Modules\Catalog\Application\Command\CreateProductCommand;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Media\Application\MediaImageService;
use Commerce\Modules\Migration\Contract\MigrationSourceInterface;
use Commerce\Modules\Migration\Domain\MigrationEntityType;
use Commerce\Modules\Migration\Domain\MigrationRecord;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final readonly class MigrationCatalogImporter
{
    public function __construct(
        private Connection $db,
        private PublicIdFactory $ids,
        private CategoryWriter $categories,
        private ProductWriter $products,
        private SeoUrlManager $seo,
        private MediaImageService $media,
        private MigrationIdMap $idMap,
        private MigrationRunJournal $runs,
    ) {}

    public function import(MigrationSourceInterface $source, MigrationImportPlan $plan, ?string $resumeRunId = null): MigrationImportResult
    {
        $runId = $resumeRunId ?: $this->ids->rfc4122();
        $sourceHash = $this->idMap->sourceHash($plan->sourceInstanceKey);
        $counts = ['created'=>0,'reused'=>0,'skipped'=>0,'failed'=>0];
        $issues = 0;

        if ($resumeRunId === null) {
            $this->runs->start($runId, $source->code(), [
                'store_id'=>$plan->storeId,'market_id'=>$plan->marketId,'primary_locale'=>$plan->primaryLocale,
                'currency'=>$plan->currency,'source_instance_hash'=>bin2hex($sourceHash),
            ]);
        }

        try {
            $this->assertTargetStoreMarket($plan);
            $this->importLocales($source, $plan, $counts, $issues);
            $this->importCurrencies($source, $plan, $counts, $issues);
            $this->assertTargetContext($plan);
            $this->importBrands($source, $plan, $sourceHash, $runId, $counts, $issues);
            $this->importCategories($source, $plan, $sourceHash, $runId, $counts, $issues);
            $this->importAttributes($source, $plan, $sourceHash, $runId, $counts, $issues);
            $this->importProducts($source, $plan, $sourceHash, $runId, $counts, $issues);
            $this->importCustomers($source, $plan, $sourceHash, $runId, $counts, $issues);
            $this->importOrders($source, $plan, $sourceHash, $runId, $counts, $issues);
            $this->runs->complete($runId);
            $this->finishJob($runId, 'completed', $counts, $issues);
            return new MigrationImportResult($runId, 'completed', $counts, $issues);
        } catch (\Throwable $e) {
            $this->runs->fail($runId, $e->getMessage());
            $this->finishJob($runId, 'failed', $counts, $issues);
            throw $e;
        }
    }

    /** @param array<string,int> $counts */
    private function importLocales(MigrationSourceInterface $source, MigrationImportPlan $plan, array &$counts, int &$issues): void
    {
        if (!in_array(MigrationEntityType::Locale, $source->supportedEntities(), true)) return;
        $this->walk($source, MigrationEntityType::Locale, $plan->batchSize, function(MigrationRecord $r) use($plan,&$counts,&$issues): void {
            $sourceCode=(string)($r->data['code']??''); $code=$plan->mappedLocale($sourceCode);
            if($code===null){$issues++;$counts['skipped']++;return;}
            $now=$this->now();
            if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_locale WHERE code=?',[$code])===0){
                $parts=explode('-',$code,2);$this->db->insert('mc_locale',['code'=>$code,'language_code'=>strtolower($parts[0]),'region_code'=>isset($parts[1])?strtoupper($parts[1]):null,'name'=>(string)($r->data['name']??$code),'native_name'=>(string)($r->data['name']??$code),'direction'=>'ltr','enabled'=>(bool)($r->data['enabled']??true)?1:0,'created_at'=>$now,'updated_at'=>$now]);$counts['created']++;
            } else $counts['reused']++;
            $this->db->executeStatement('INSERT INTO mc_store_locale (store_id,locale_code,enabled,is_default,url_prefix,sort_order) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),sort_order=VALUES(sort_order)',[$plan->storeId,$code,(bool)($r->data['enabled']??true)?1:0,$code===$plan->primaryLocale?1:0,null,(int)($r->data['sort_order']??100)]);
        });
    }

    /** @param array<string,int> $counts */
    private function importCurrencies(MigrationSourceInterface $source, MigrationImportPlan $plan, array &$counts, int &$issues): void
    {
        if (!in_array(MigrationEntityType::Currency, $source->supportedEntities(), true)) return;
        $this->walk($source, MigrationEntityType::Currency, $plan->batchSize, function(MigrationRecord $r) use($plan,&$counts): void {
            $code=strtoupper((string)($r->data['code']??'')); if(!preg_match('/^[A-Z]{3}$/',$code)){ $counts['skipped']++; return; }
            $now=$this->now();
            if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_currency WHERE code=?',[$code])===0){
                $this->db->insert('mc_currency',['code'=>$code,'name'=>(string)($r->data['name']??$code),'symbol'=>(string)($r->data['symbol']??$code),'minor_units'=>(int)($r->data['minor_units']??2),'enabled'=>(bool)($r->data['enabled']??true)?1:0,'created_at'=>$now,'updated_at'=>$now]);$counts['created']++;
            } else $counts['reused']++;
            $this->db->executeStatement('INSERT INTO mc_store_currency (store_id,currency_code,enabled,is_default,sort_order) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)',[$plan->storeId,$code,(bool)($r->data['enabled']??true)?1:0,$code===$plan->currency?1:0,100]);
        });
    }

    /** @param array<string,int> $counts */
    private function importBrands(MigrationSourceInterface $source, MigrationImportPlan $plan, string $sourceHash, string $runId, array &$counts, int &$issues): void
    {
        if (!in_array(MigrationEntityType::Brand, $source->supportedEntities(), true)) return;
        $this->walk($source, MigrationEntityType::Brand, $plan->batchSize, function(MigrationRecord $r) use($source,$plan,$sourceHash,$runId,&$counts,&$issues): void {
            if($this->mappedInternal($source,$sourceHash,'brand',$r->sourceKey,'mc_brand')!==null){$counts['reused']++;return;}
            try{$this->db->transactional(function(Connection $db) use($r,$source,$plan,$sourceHash,$runId,&$counts): void {
                $name=trim((string)($r->data['name']??'')); if($name==='') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.588b96752d53'));
                $existing=$db->fetchAssociative('SELECT id,public_id FROM mc_brand WHERE normalized_name=? LIMIT 1',[mb_strtolower($name)]);
                if(is_array($existing)){ $public=Uuid::fromBinary((string)$existing['public_id'])->toRfc4122(); $counts['reused']++; }
                else { $u=$this->ids->generate();$now=$this->now();$db->insert('mc_brand',['public_id'=>$u->toBinary(),'name'=>$name,'normalized_name'=>mb_strtolower($name),'logo_media_id'=>null,'created_at'=>$now,'updated_at'=>$now]);$id=(int)$db->lastInsertId();$db->insert('mc_store_brand',['store_id'=>$plan->storeId,'brand_id'=>$id,'status'=>'active','sort_order'=>(int)($r->data['sort_order']??0)]);$public=$u->toRfc4122();$counts['created']++;$this->recordItem($runId,'brand',$r,$public,'created'); }
                $primaryKeyword=$this->seoKeywordForLocale($r->data,$plan,$plan->primaryLocale);$route=$this->seo->ensureForCreatedEntity($plan->storeId,$plan->primaryLocale,SeoEntityType::Brand,$public,$name,$primaryKeyword);if($primaryKeyword!==null)$this->seo->preserveLegacyPath($route,$primaryKeyword);$this->importAdditionalSeoRoutes($public,SeoEntityType::Brand,$name,$r->data,$plan);
                $this->idMap->save($source->code(),$sourceHash,'brand',$r->sourceKey,$public);
            });}catch(\Throwable $e){$issues++;$counts['failed']++;$this->recordIssue($runId,'brand',$r->sourceKey,'brand_import_failed',$e->getMessage());}
        });
    }

    /** @param array<string,int> $counts */
    private function importCategories(MigrationSourceInterface $source, MigrationImportPlan $plan, string $sourceHash, string $runId, array &$counts, int &$issues): void
    {
        if (!in_array(MigrationEntityType::Category, $source->supportedEntities(), true)) return;
        $pending=$this->collect($source,MigrationEntityType::Category,$plan->batchSize);$guard=0;
        while($pending!==[] && $guard++<100){$progress=false;
            foreach($pending as $key=>$r){$parentKey=$r->data['parent_source_key']??null;$parentId=null;if(is_string($parentKey)&&$parentKey!==''){$parentId=$this->mappedInternal($source,$sourceHash,'category',$parentKey,'mc_category');if($parentId===null)continue;}
                if($this->mappedInternal($source,$sourceHash,'category',$r->sourceKey,'mc_category')!==null){$counts['reused']++;unset($pending[$key]);$progress=true;continue;}
                try{$translations=(array)($r->data['translations']??[]);$name=$this->translationField($translations,$plan,'name')??('Category '.$r->sourceKey);$manual=$this->seoKeyword($r->data,$plan);
                    $created=$this->categories->create(new CreateCategoryCommand($plan->storeId,$plan->marketId,$plan->primaryLocale,$name,$parentId,$manual,(int)($r->data['sort_order']??0)));
                    $this->addCategoryTranslations((int)$created['id'],(string)$created['public_id'],$translations,$plan,(array)($r->data['seo_urls']??[]));$this->preservePrimaryLegacyPath((string)$created['public_id'],SeoEntityType::Category,$r->data,$plan);
                    if(!$plan->publishCategories){$this->db->update('mc_category',['status'=>'inactive'],['id'=>(int)$created['id']]);$this->db->update('mc_store_category',['status'=>'inactive'],['store_id'=>$plan->storeId,'category_id'=>(int)$created['id']]);$this->db->update('mc_market_category',['status'=>'inactive'],['market_id'=>$plan->marketId,'category_id'=>(int)$created['id']]);}
                    $this->idMap->save($source->code(),$sourceHash,'category',$r->sourceKey,(string)$created['public_id']);$this->recordItem($runId,'category',$r,(string)$created['public_id'],'created');$counts['created']++;unset($pending[$key]);$progress=true;
                }catch(\Throwable $e){$issues++;$counts['failed']++;$this->recordIssue($runId,'category',$r->sourceKey,'category_import_failed',$e->getMessage());unset($pending[$key]);$progress=true;}
            }
            if(!$progress)break;
        }
        foreach($pending as $r){$issues++;$counts['failed']++;$this->recordIssue($runId,'category',$r->sourceKey,'category_parent_unresolved','Category parent could not be resolved without creating a broken hierarchy.');}
    }

    /** @param array<string,int> $counts */
    private function importAttributes(MigrationSourceInterface $source, MigrationImportPlan $plan, string $sourceHash, string $runId, array &$counts, int &$issues): void
    {
        if (!in_array(MigrationEntityType::Attribute, $source->supportedEntities(), true)) return;
        $this->walk($source,MigrationEntityType::Attribute,$plan->batchSize,function(MigrationRecord $r) use($source,$sourceHash,$runId,&$counts,&$issues): void {
            $code='oc_attr_'.preg_replace('/[^A-Za-z0-9_]/','_',$r->sourceKey);$existing=$this->db->fetchAssociative('SELECT id,public_id FROM mc_attribute_definition WHERE code=? LIMIT 1',[$code]);
            try{if(is_array($existing)){$id=(int)$existing['id'];$public=Uuid::fromBinary((string)$existing['public_id'])->toRfc4122();$counts['reused']++;}else{$u=$this->ids->generate();$this->db->insert('mc_attribute_definition',['public_id'=>$u->toBinary(),'code'=>$code,'data_type'=>'text','filterable'=>0,'comparable'=>1,'sort_order'=>(int)($r->data['sort_order']??0)]);$id=(int)$this->db->lastInsertId();$public=$u->toRfc4122();$counts['created']++;$this->recordItem($runId,'attribute',$r,$public,'created');}
                foreach((array)($r->data['translations']??[]) as $locale=>$name){$this->db->executeStatement('INSERT INTO mc_attribute_translation (attribute_id,locale,name,unit_label) VALUES (?,?,?,NULL) ON DUPLICATE KEY UPDATE name=VALUES(name)',[$id,(string)$locale,mb_substr((string)$name,0,190)]);} $this->idMap->save($source->code(),$sourceHash,'attribute',$r->sourceKey,$public);
            }catch(\Throwable $e){$issues++;$counts['failed']++;$this->recordIssue($runId,'attribute',$r->sourceKey,'attribute_import_failed',$e->getMessage());}
        });
    }

    /** @param array<string,int> $counts */
    private function importProducts(MigrationSourceInterface $source, MigrationImportPlan $plan, string $sourceHash, string $runId, array &$counts, int &$issues): void
    {
        if (!in_array(MigrationEntityType::Product, $source->supportedEntities(), true)) return;
        $processed=0;
        $this->walk($source,MigrationEntityType::Product,$plan->batchSize,function(MigrationRecord $r) use($source,$plan,$sourceHash,$runId,&$counts,&$issues,&$processed): void {
            if($this->mappedInternal($source,$sourceHash,'product',$r->sourceKey,'mc_product')!==null){$counts['reused']++;$processed++;return;}
            try{$data=$r->data;$translations=(array)($data['translations']??[]);$name=$this->translationField($translations,$plan,'name')??('Product '.$r->sourceKey);$sku=$this->safeSku((string)($data['sku']??''),$r->sourceKey);$brandId=null;if(is_string($data['brand_source_key']??null))$brandId=$this->mappedInternal($source,$sourceHash,'brand',(string)$data['brand_source_key'],'mc_brand');
                $catIds=[];foreach((array)($data['category_source_keys']??[]) as $ck){$cid=$this->mappedInternal($source,$sourceHash,'category',(string)$ck,'mc_category');if($cid!==null)$catIds[]=$cid;}
                $created=$this->products->create(new CreateProductCommand($plan->storeId,$plan->marketId,$plan->primaryLocale,$name,$sku,$this->minor((string)($data['legacy_price_decimal']??'0'),$plan->currency),$plan->currency,$this->stock((int)($data['quantity']??0)),'item','physical',$catIds,$this->seoKeyword($data,$plan),null,$this->translationField($translations,$plan,'description'),$data['gtin']??null,$data['mpn']??null,$brandId));
                $pid=(int)$created['id'];$this->addProductTranslations($pid,(string)$created['public_id'],$translations,$plan,(array)($data['seo_urls']??[]));$this->preservePrimaryLegacyPath((string)$created['public_id'],SeoEntityType::Product,$data,$plan);$this->importProductAttributes($pid,(array)($data['attributes']??[]),$source,$sourceHash);$this->importProductOptions($pid,(array)($data['options']??[]));$this->importLegacyPrices((int)$created['variant_id'],(array)($data['specials']??[]),(array)($data['discounts']??[]),$plan);$this->importProductMedia($pid,$name,$data,$plan,$runId,$r,$issues);
                if($plan->publishProducts && (bool)($data['enabled']??true)){$now=$this->now();$this->db->update('mc_product',['status'=>'published','updated_at'=>$now],['id'=>$pid]);$this->db->update('mc_store_product',['status'=>'active','published_at'=>$now],['store_id'=>$plan->storeId,'product_id'=>$pid]);$this->db->update('mc_market_product',['status'=>'active','published_at'=>$now],['market_id'=>$plan->marketId,'product_id'=>$pid]);}
                elseif(!(bool)($data['enabled']??true)){$this->db->update('mc_store_product',['status'=>'inactive'],['store_id'=>$plan->storeId,'product_id'=>$pid]);$this->db->update('mc_market_product',['status'=>'inactive'],['market_id'=>$plan->marketId,'product_id'=>$pid]);}
                $this->idMap->save($source->code(),$sourceHash,'product',$r->sourceKey,(string)$created['public_id']);$this->recordItem($runId,'product',$r,(string)$created['public_id'],'created');$counts['created']++;
            }catch(\Throwable $e){$issues++;$counts['failed']++;$this->recordIssue($runId,'product',$r->sourceKey,'product_import_failed',$e->getMessage());}
            $processed++; if($processed%$plan->batchSize===0)$this->runs->checkpoint($runId,'product',(string)$r->sourceKey,$processed,$issues);
        });
    }

    /** @param array<string,int> $counts */
    private function importCustomers(MigrationSourceInterface $source, MigrationImportPlan $plan, string $sourceHash, string $runId, array &$counts, int &$issues): void
    {
        if(!in_array(MigrationEntityType::Customer,$source->supportedEntities(),true))return;
        $this->walk($source,MigrationEntityType::Customer,$plan->batchSize,function(MigrationRecord $r) use($source,$plan,$sourceHash,$runId,&$counts,&$issues): void {
            $mappedCustomer=$this->idMap->find($source->code(),$sourceHash,'customer',$r->sourceKey);if($mappedCustomer!==null&&$this->targetByPublic('mc_customer',$mappedCustomer)!==null){$counts['reused']++;return;}
            try{$email=trim((string)($r->data['email']??''));$existing=$email!==''?$this->db->fetchAssociative('SELECT id,public_id FROM mc_customer WHERE email_normalized=? LIMIT 1',[mb_strtolower($email)]):false;
                if(is_array($existing)){$public=Uuid::fromBinary((string)$existing['public_id'])->toRfc4122();$counts['reused']++;}
                else{$u=$this->ids->generate();$now=$this->legacyDate((string)($r->data['legacy_created_at']??''));$this->db->insert('mc_customer',['public_id'=>$u->toBinary(),'email'=>$email!==''?$email:null,'email_normalized'=>$email!==''?mb_strtolower($email):null,'phone_e164'=>$this->phone((string)($r->data['phone']??'')),'display_name'=>mb_substr((string)($r->data['display_name']??''),0,190),'locale'=>$plan->primaryLocale,'password_hash'=>null,'status'=>(bool)($r->data['enabled']??true)?'active':'disabled','created_at'=>$now,'updated_at'=>$now,'last_seen_at'=>null]);$public=$u->toRfc4122();$counts['created']++;$this->recordItem($runId,'customer',$r,$public,'created');}
                $this->idMap->save($source->code(),$sourceHash,'customer',$r->sourceKey,$public);
            }catch(\Throwable $e){$issues++;$counts['failed']++;$this->recordIssue($runId,'customer',$r->sourceKey,'customer_import_failed',$e->getMessage());}
        });
    }

    /** @param array<string,int> $counts */
    private function importOrders(MigrationSourceInterface $source, MigrationImportPlan $plan, string $sourceHash, string $runId, array &$counts, int &$issues): void
    {
        if(!in_array(MigrationEntityType::Order,$source->supportedEntities(),true))return;
        $this->walk($source,MigrationEntityType::Order,$plan->batchSize,function(MigrationRecord $r) use($source,$plan,$sourceHash,$runId,&$counts,&$issues): void {
            $mappedOrder=$this->idMap->find($source->code(),$sourceHash,'order',$r->sourceKey);if($mappedOrder!==null&&$this->targetByPublic('mc_sales_order',$mappedOrder)!==null){$counts['reused']++;return;}
            try{$this->db->transactional(function(Connection $db) use($r,$source,$plan,$sourceHash,$runId,&$counts): void {
                $d=$r->data;$currency=preg_match('/^[A-Z]{3}$/',(string)($d['currency']??''))?(string)$d['currency']:$plan->currency;$customerId=null;if(is_string($d['customer_source_key']??null)){$cp=$this->idMap->find($source->code(),$sourceHash,'customer',(string)$d['customer_source_key']);if($cp)$customerId=$this->targetByPublic('mc_customer',$cp);}
                $u=$this->ids->generate();$created=$this->legacyDate((string)($d['legacy_created_at']??''));$updated=$this->legacyDate((string)($d['legacy_updated_at']??''));$totals=$this->legacyTotals((array)($d['totals']??[]),$currency);$orderNumber='OC-'.preg_replace('/[^A-Za-z0-9_-]/','',$r->sourceKey);
                if((int)$db->fetchOne('SELECT COUNT(*) FROM mc_sales_order WHERE store_id=? AND order_number=?',[$plan->storeId,$orderNumber])>0)$orderNumber='OC-'.$r->sourceKey.'-'.substr(hash('sha256',$plan->sourceInstanceKey),0,8);
                $db->insert('mc_sales_order',['public_id'=>$u->toBinary(),'store_id'=>$plan->storeId,'customer_id'=>$customerId,'order_number'=>$orderNumber,'checkout_idempotency_key'=>null,'status'=>'placed','payment_status'=>'unknown','fulfillment_status'=>'unfulfilled','currency'=>$currency,'prices_include_tax'=>1,'subtotal_minor'=>$totals['subtotal'],'discount_minor'=>$totals['discount'],'shipping_minor'=>$totals['shipping'],'tax_minor'=>$totals['tax'],'tax_country_code'=>null,'tax_calculation_mode'=>'legacy_import','total_minor'=>$this->minor((string)($d['total_decimal']??'0'),$currency),'customer_email'=>$d['email']?:null,'customer_email_normalized'=>$d['email']?mb_strtolower((string)$d['email']):null,'customer_phone'=>$d['phone']?:null,'customer_name'=>mb_substr((string)($d['customer_name']??''),0,190),'locale'=>$plan->primaryLocale,'created_at'=>$created,'updated_at'=>$updated,'row_version'=>1]);$orderId=(int)$db->lastInsertId();
                foreach((array)($d['items']??[]) as $item){$productId=$variantId=null;$mapped=$this->idMap->find($source->code(),$sourceHash,'product',(string)($item['product_source_key']??''));if($mapped){$productId=$this->targetByPublic('mc_product',$mapped);if($productId)$variantId=(int)$db->fetchOne('SELECT id FROM mc_product_variant WHERE product_id=? ORDER BY sort_order,id LIMIT 1',[$productId]);}
                    $unit=$this->minor((string)($item['unit_price_decimal']??'0'),$currency);$line=$this->minor((string)($item['line_total_decimal']??'0'),$currency);$tax=$this->minor((string)($item['tax_decimal']??'0'),$currency);$snapshot=['legacy_source'=>$source->code(),'legacy_order_id'=>$r->sourceKey,'legacy_product_id'=>$item['product_source_key']??null,'payment'=>$d['payment']??[],'shipping'=>$d['shipping']??[],'payment_address'=>$d['payment_address']??[],'shipping_address'=>$d['shipping_address']??[],'legacy_status_id'=>$d['legacy_status_id']??null];
                    $db->insert('mc_sales_order_item',['order_id'=>$orderId,'product_id'=>$productId,'variant_id'=>$variantId,'sku'=>mb_substr((string)($item['sku']??''),0,190),'name'=>mb_substr((string)($item['name']??''),0,255),'quantity'=>max(1,(int)($item['quantity']??1)),'unit_price_minor'=>$unit,'unit_price_net_minor'=>null,'unit_price_gross_minor'=>$unit,'line_total_minor'=>$line,'tax_minor'=>$tax,'tax_rate_bps'=>0,'tax_class_code'=>null,'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
                }
                $this->idMap->save($source->code(),$sourceHash,'order',$r->sourceKey,$u->toRfc4122());$this->recordItem($runId,'order',$r,$u->toRfc4122(),'created');$counts['created']++;
            });}catch(\Throwable $e){$issues++;$counts['failed']++;$this->recordIssue($runId,'order',$r->sourceKey,'order_import_failed',$e->getMessage());}
        });
    }

    public function rollback(string $runId): array
    {
        if ($this->runs->load($runId) === null) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7ca360d1003a'));
        }
        $jobId = $this->db->fetchOne("SELECT id FROM mc_import_job WHERE JSON_UNQUOTE(JSON_EXTRACT(options,'$.run_id'))=? ORDER BY id DESC LIMIT 1", [$runId]);
        if ($jobId === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7276f9eec3a4'));
        }
        $rows=$this->db->fetchAllAssociative("SELECT id,entity_type,target_public_id FROM mc_import_item WHERE job_id=? AND status='created' AND target_public_id IS NOT NULL ORDER BY id DESC",[(int)$jobId]);
        $deleted=0;$skipped=0;
        $tables=['order'=>'mc_sales_order','customer'=>'mc_customer','product'=>'mc_product','category'=>'mc_category','brand'=>'mc_brand','attribute'=>'mc_attribute_definition'];
        foreach($rows as $row){$type=(string)$row['entity_type'];$bin=$row['target_public_id'];if(!isset($tables[$type])){$skipped++;continue;}try{$id=$this->db->fetchOne('SELECT id FROM '.$tables[$type].' WHERE public_id=? LIMIT 1',[$bin]);if($id===false){$skipped++;$this->db->update('mc_import_item',['status'=>'rolled_back','processed_at'=>$this->now()],['id'=>(int)$row['id']]);continue;}$this->db->delete($tables[$type],['id'=>(int)$id]);$this->db->update('mc_import_item',['status'=>'rolled_back','processed_at'=>$this->now()],['id'=>(int)$row['id']]);$deleted++;}catch(\Throwable){$skipped++;}}
        $now=$this->now();
        $this->db->update('mc_import_job',['status'=>'rolled_back','updated_at'=>$now,'completed_at'=>$now],['id'=>(int)$jobId]);
        $this->runs->rolledBack($runId);
        return ['deleted'=>$deleted,'skipped'=>$skipped];
    }

    private function assertTargetStoreMarket(MigrationImportPlan $plan): void
    {
        if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_store WHERE id=?',[$plan->storeId])!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0b1e0b011042'));
        if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_market WHERE id=? AND store_id=?',[$plan->marketId,$plan->storeId])!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ccfbd61501f8'));
    }

    private function assertTargetContext(MigrationImportPlan $plan): void
    {
        if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1',[$plan->storeId,$plan->primaryLocale])!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.553961baab09'));
        if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_store_currency WHERE store_id=? AND currency_code=? AND enabled=1',[$plan->storeId,$plan->currency])!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.215cd2855c32'));
    }

    private function addCategoryTranslations(int $id,string $public,array $translations,MigrationImportPlan $plan,array $seoRows=[]): void {foreach($translations as $src=>$t){$locale=$plan->mappedLocale((string)$src);if($locale===null||$locale===$plan->primaryLocale||!is_array($t)||trim((string)($t['name']??''))==='')continue;if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1',[$plan->storeId,$locale])!==1)continue;$this->db->executeStatement('INSERT INTO mc_category_translation (category_id,store_id,locale,name,slug,description,meta_title,meta_description) VALUES (?,?,?,?,NULL,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),meta_title=VALUES(meta_title),meta_description=VALUES(meta_description)',[$id,$plan->storeId,$locale,(string)$t['name'],(string)($t['description']??''),$t['meta_title']??null,$t['meta_description']??null]);$keyword=$this->seoKeywordForMappedLocale($seoRows,$plan,$locale);$route=$this->seo->ensureForCreatedEntity($plan->storeId,$locale,SeoEntityType::Category,$public,(string)$t['name'],$keyword);if($keyword!==null)$this->seo->preserveLegacyPath($route,$keyword);}}
    private function addProductTranslations(int $id,string $public,array $translations,MigrationImportPlan $plan,array $seoRows=[]): void {foreach($translations as $src=>$t){$locale=$plan->mappedLocale((string)$src);if($locale===null||$locale===$plan->primaryLocale||!is_array($t)||trim((string)($t['name']??''))==='')continue;if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1',[$plan->storeId,$locale])!==1)continue;$now=$this->now();$this->db->executeStatement('INSERT INTO mc_product_translation (product_id,store_id,locale,name,slug,short_description,description,meta_title,meta_description,created_at,updated_at) VALUES (?,?,?,?,NULL,NULL,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),meta_title=VALUES(meta_title),meta_description=VALUES(meta_description),updated_at=VALUES(updated_at)',[$id,$plan->storeId,$locale,(string)$t['name'],(string)($t['description']??''),$t['meta_title']??null,$t['meta_description']??null,$now,$now]);$keyword=$this->seoKeywordForMappedLocale($seoRows,$plan,$locale);$route=$this->seo->ensureForCreatedEntity($plan->storeId,$locale,SeoEntityType::Product,$public,(string)$t['name'],$keyword);if($keyword!==null)$this->seo->preserveLegacyPath($route,$keyword);}}
    private function importProductAttributes(int $pid,array $attrs,MigrationSourceInterface $source,string $hash): void {foreach($attrs as $sourceKey=>$values){$p=$this->idMap->find($source->code(),$hash,'attribute',(string)$sourceKey);if(!$p)continue;$aid=$this->targetByPublic('mc_attribute_definition',$p);if(!$aid)continue;foreach((array)$values as $locale=>$value){$text=trim((string)$value);if($text==='')continue;$this->db->insert('mc_product_attribute_value',['product_id'=>$pid,'variant_id'=>null,'attribute_id'=>$aid,'locale'=>(string)$locale,'value_text'=>mb_substr($text,0,1000),'value_text_hash'=>hash('sha256',$text,true),'value_decimal'=>null,'value_boolean'=>null,'value_json'=>null,'sort_order'=>0]);}}}
    private function importProductOptions(int $pid,array $options): void {foreach($options as $idx=>$o){if(!is_array($o))continue;$u=$this->ids->generate();$code='oc_opt_'.preg_replace('/[^A-Za-z0-9_]/','_',(string)($o['option_source_key']??$idx));$this->db->executeStatement('INSERT IGNORE INTO mc_product_option (public_id,product_id,code,sort_order) VALUES (?,?,?,?)',[$u->toBinary(),$pid,$code,$idx*10]);$oid=(int)$this->db->fetchOne('SELECT id FROM mc_product_option WHERE product_id=? AND code=?',[$pid,$code]);foreach((array)($o['translations']??[]) as $loc=>$name)$this->db->executeStatement('INSERT INTO mc_product_option_translation (option_id,locale,name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)',[$oid,(string)$loc,mb_substr((string)$name,0,190)]);foreach((array)($o['values']??[]) as $v){if(!is_array($v))continue;$vu=$this->ids->generate();$vcode='oc_val_'.preg_replace('/[^A-Za-z0-9_]/','_',(string)($v['option_value_source_key']??$v['source_key']??''));$this->db->executeStatement('INSERT IGNORE INTO mc_product_option_value (public_id,option_id,code,sort_order) VALUES (?,?,?,?)',[$vu->toBinary(),$oid,$vcode,(int)($v['sort_order']??0)]);$vid=(int)$this->db->fetchOne('SELECT id FROM mc_product_option_value WHERE option_id=? AND code=?',[$oid,$vcode]);foreach((array)($v['translations']??[]) as $loc=>$name)$this->db->executeStatement('INSERT INTO mc_product_option_value_translation (option_value_id,locale,name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)',[$vid,(string)$loc,mb_substr((string)$name,0,190)]);}}}
    private function importLegacyPrices(int $variantId,array $specials,array $discounts,MigrationImportPlan $plan): void {$now=$this->now();foreach(array_merge($specials,$discounts) as $row){if(!is_array($row))continue;$price=$this->minor((string)($row['price_decimal']??'0'),$plan->currency);$qty=max(1,(int)($row['quantity']??1));$group='legacy:'.(int)($row['customer_group_id']??0);$start=$this->nullableDate((string)($row['date_start']??''));$end=$this->nullableDate((string)($row['date_end']??''));$this->db->insert('mc_price',['variant_id'=>$variantId,'store_id'=>$plan->storeId,'price_list_id'=>null,'market_id'=>$plan->marketId,'currency'=>$plan->currency,'customer_group'=>$group,'min_quantity'=>$qty,'max_quantity'=>null,'amount_minor'=>$price,'compare_at_minor'=>null,'tax_included'=>1,'priority'=>(int)($row['priority']??100),'starts_at'=>$start,'ends_at'=>$end,'created_at'=>$now,'updated_at'=>$now]);}}
    private function importProductMedia(int $pid,string $name,array $data,MigrationImportPlan $plan,string $runId,MigrationRecord $r,int &$issues): void {if($plan->sourceImageRoot===null||trim($plan->sourceImageRoot)==='')return;$root=realpath($plan->sourceImageRoot);if($root===false)return;$items=[];if(is_string($data['main_image']??null)&&trim((string)$data['main_image'])!=='')$items[]=['path'=>(string)$data['main_image'],'role'=>'primary','sort_order'=>0];foreach((array)($data['images']??[]) as $im)if(is_array($im))$items[]=['path'=>(string)($im['path']??''),'role'=>'gallery','sort_order'=>(int)($im['sort_order']??0)];foreach($items as $im){$rel=ltrim(str_replace('\\','/',(string)$im['path']),'/');if(str_contains($rel,'../')){$issues++;$this->recordIssue($runId,'product',$r->sourceKey,'unsafe_media_path','Rejected source image path traversal.');continue;}$candidate=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel));if($candidate===false||!str_starts_with($candidate,$root.DIRECTORY_SEPARATOR)||!is_file($candidate)){$issues++;$this->recordIssue($runId,'product',$r->sourceKey,'missing_media','Source image was not found: '.$rel);continue;}try{$upload=new UploadedFile($candidate,basename($candidate),null,UPLOAD_ERR_OK,true);$asset=$this->media->upload($upload,$plan->storeId);$this->media->attachToProduct($pid,$asset->assetId,(string)$im['role'],(int)$im['sort_order'],$name);}catch(\Throwable $e){$issues++;$this->recordIssue($runId,'product',$r->sourceKey,'media_import_failed',$e->getMessage());}}}

    private function mappedInternal(MigrationSourceInterface $source,string $hash,string $type,string $key,string $table): ?int {$p=$this->idMap->find($source->code(),$hash,$type,$key);return $p?$this->idMap->targetInternalId($table,$p):null;}
    private function targetByPublic(string $table,string $public): ?int {if(!in_array($table,['mc_customer','mc_product','mc_category','mc_brand','mc_attribute_definition','mc_sales_order'],true))return null;$id=$this->db->fetchOne('SELECT id FROM '.$table.' WHERE public_id=? LIMIT 1',[Uuid::fromString($public)->toBinary()]);return $id===false?null:(int)$id;}
    private function translationField(array $translations,MigrationImportPlan $plan,string $field): ?string {$preferred=$translations[$plan->primaryLocale]??null;if(is_array($preferred)&&trim((string)($preferred[$field]??''))!=='')return (string)$preferred[$field];foreach($translations as $src=>$t){if(!is_array($t)||$plan->mappedLocale((string)$src)!==$plan->primaryLocale)continue;if(trim((string)($t[$field]??''))!=='')return (string)$t[$field];}foreach($translations as $t)if(is_array($t)&&trim((string)($t[$field]??''))!=='')return (string)$t[$field];return null;}
    private function seoKeyword(array $data,MigrationImportPlan $plan): ?string {return $this->seoKeywordForLocale($data,$plan,$plan->primaryLocale);}
    private function seoKeywordForLocale(array $data,MigrationImportPlan $plan,string $targetLocale): ?string {return $this->seoKeywordForMappedLocale((array)($data['seo_urls']??[]),$plan,$targetLocale);}
    private function seoKeywordForMappedLocale(array $rows,MigrationImportPlan $plan,string $targetLocale): ?string {foreach($rows as $row){if(!is_array($row))continue;$loc=$row['language_code']??null;if($loc!==null&&$plan->mappedLocale((string)$loc)!==$targetLocale)continue;$k=trim((string)($row['keyword']??''));if($k!=='')return trim($k,'/');}return null;}
    private function preservePrimaryLegacyPath(string $public,SeoEntityType $type,array $data,MigrationImportPlan $plan): void {$keyword=$this->seoKeywordForLocale($data,$plan,$plan->primaryLocale);if($keyword===null)return;$route=$this->seo->ensureForCreatedEntity($plan->storeId,$plan->primaryLocale,$type,$public,$keyword,$keyword);$this->seo->preserveLegacyPath($route,$keyword);}
    private function importAdditionalSeoRoutes(string $public,SeoEntityType $type,string $displayName,array $data,MigrationImportPlan $plan): void {foreach((array)($data['seo_urls']??[]) as $row){if(!is_array($row))continue;$sourceLocale=(string)($row['language_code']??'');$locale=$sourceLocale!==''?$plan->mappedLocale($sourceLocale):$plan->primaryLocale;if($locale===null||$locale===$plan->primaryLocale)continue;if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1',[$plan->storeId,$locale])!==1)continue;$keyword=trim((string)($row['keyword']??''),'/');if($keyword==='')continue;$route=$this->seo->ensureForCreatedEntity($plan->storeId,$locale,$type,$public,$displayName,$keyword);$this->seo->preserveLegacyPath($route,$keyword);}}
    private function safeSku(string $sku,string $sourceKey): string {$sku=trim($sku);if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,189}$/',$sku)===1&&$this->db->fetchOne('SELECT COUNT(*) FROM mc_product_variant WHERE sku=?',[$sku])==0)return $sku;return 'oc-'.$sourceKey.'-'.substr(hash('sha256',$sku.'|'.$sourceKey),0,10);}
    private function stock(int $q): string {return number_format(max(0,$q),6,'.','');}
    private function minor(string $decimal,string $currency): int {$units=$this->db->fetchOne('SELECT minor_units FROM mc_currency WHERE code=?',[$currency]);$scale=$units===false?2:max(0,min(4,(int)$units));$v=(float)str_replace(',','.',$decimal);return max(0,(int)round($v*(10**$scale)));}
    private function legacyTotals(array $rows,string $currency): array {$out=['subtotal'=>0,'discount'=>0,'shipping'=>0,'tax'=>0];foreach($rows as $r){if(!is_array($r))continue;$v=$this->minor((string)($r['value_decimal']??'0'),$currency);$code=(string)($r['code']??'');if($code==='sub_total')$out['subtotal']=$v;elseif($code==='shipping')$out['shipping']=$v;elseif($code==='tax')$out['tax']+=$v;elseif(in_array($code,['coupon','voucher','reward'],true))$out['discount']+=abs($v);}return $out;}
    private function phone(string $phone): ?string {$v=preg_replace('/[^0-9+]/','',$phone)??'';return $v===''?null:mb_substr($v,0,32);}
    private function legacyDate(string $v): string {$t=strtotime($v);return $t===false?$this->now():gmdate('Y-m-d H:i:s.000000',$t);}
    private function nullableDate(string $v): ?string {if($v===''||$v==='0000-00-00'||str_starts_with($v,'0000-00-00'))return null;$t=strtotime($v);return $t===false?null:gmdate('Y-m-d H:i:s',$t);}
    private function now(): string {return gmdate('Y-m-d H:i:s.000000');}
    /** @return array<string,MigrationRecord> */ private function collect(MigrationSourceInterface $s,MigrationEntityType $t,int $limit): array {$out=[];$this->walk($s,$t,$limit,function(MigrationRecord $r) use(&$out):void{$out[$r->sourceKey]=$r;});return $out;}
    private function walk(MigrationSourceInterface $s,MigrationEntityType $t,int $limit,callable $fn): void {$cursor=null;do{$b=$s->read($t,$cursor,$limit);foreach($b->records as $r)$fn($r);$cursor=$b->nextCursor;}while(!$b->complete);}
    private function jobId(string $runId): int {$id=$this->db->fetchOne("SELECT id FROM mc_import_job WHERE JSON_UNQUOTE(JSON_EXTRACT(options,'$.run_id'))=? ORDER BY id DESC LIMIT 1",[$runId]);if($id!==false)return (int)$id;$u=$this->ids->generate();$now=$this->now();$this->db->insert('mc_import_job',['public_id'=>$u->toBinary(),'source_type'=>'migration_run','source_label'=>'Migration '.$runId,'status'=>'running','mode'=>'import','options'=>json_encode(['run_id'=>$runId],JSON_THROW_ON_ERROR),'statistics'=>null,'cursor_state'=>null,'started_at'=>$now,'completed_at'=>null,'created_at'=>$now,'updated_at'=>$now]);return (int)$this->db->lastInsertId();}
    private function recordItem(string $runId,string $type,MigrationRecord $r,string $public,string $status): void {$job=$this->jobId($runId);$bin=Uuid::fromString($public)->toBinary();$checksum=hash('sha256',json_encode($r->data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true);$this->db->executeStatement('INSERT INTO mc_import_item (job_id,entity_type,source_key,target_public_id,status,source_checksum,error_code,error_message,processed_at) VALUES (?,?,?,?,?,?,NULL,NULL,?) ON DUPLICATE KEY UPDATE target_public_id=VALUES(target_public_id),status=VALUES(status),source_checksum=VALUES(source_checksum),processed_at=VALUES(processed_at)',[$job,$type,$r->sourceKey,$bin,$status,$checksum,$this->now()]);}
    private function recordIssue(string $runId,string $type,string $key,string $code,string $message): void {$job=$this->jobId($runId);$this->db->insert('mc_import_issue',['job_id'=>$job,'severity'=>'error','entity_type'=>$type,'source_key'=>$key,'code'=>$code,'message'=>mb_substr($message,0,1000),'context'=>null,'created_at'=>$this->now()]);}
    /** @param array<string,int> $counts */ private function finishJob(string $runId,string $status,array $counts,int $issues): void {$job=$this->jobId($runId);$now=$this->now();$this->db->update('mc_import_job',['status'=>$status,'statistics'=>json_encode(['counts'=>$counts,'issues'=>$issues],JSON_THROW_ON_ERROR),'updated_at'=>$now,'completed_at'=>$status==='completed'?$now:null],['id'=>$job]);}
}
