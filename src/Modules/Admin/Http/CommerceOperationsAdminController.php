<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Bulk\Application\ProductBulkActionService;
use Commerce\Modules\ImportExport\Application\CatalogCsvService;
use Commerce\Modules\ImportExport\Application\UniversalCatalogImportService;
use Commerce\Modules\Marketing\Application\NewsletterCampaignService;
use Commerce\Modules\Marketing\Application\MarketingSegmentService;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class CommerceOperationsAdminController extends AbstractController
{
    public function __construct(
        private readonly \Commerce\Modules\Catalog\Application\CatalogMaintenanceService $catalogMaintenance,
        private readonly AdminContextResolver $contexts,
        private readonly Connection $db,
        private readonly PublicIdFactory $ids,
        private readonly ProductBulkActionService $bulk,
        private readonly CatalogCsvService $csv,
        private readonly UniversalCatalogImportService $universalImport,
        private readonly NotificationOutbox $notifications,
        private readonly NewsletterCampaignService $campaigns,
        private readonly MarketingSegmentService $segments,
        private readonly \Commerce\Modules\Marketing\Application\CampaignTemplateService $campaignTemplates,
    ) {}

    #[Route('/admin/commerce/promotions', name:'admin_commerce_promotions', methods:['GET','POST'])]
    public function promotions(Request $request): Response
    {
        $context=$this->contexts->resolve($request);
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('promotion_save',(string)$request->request->get('_csrf_token'))){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));return $this->redirectToRoute('admin_commerce_promotions');}
            try{
                $payload=$this->promotionPayload($request);
                $now=$this->now();
                $this->db->insert('mc_promotion',['public_id'=>$this->ids->binary(),'store_id'=>$context->storeId,'name'=>$payload['name'],'code'=>$payload['code'],'status'=>'active','trigger_type'=>$payload['trigger_type'],'discount_type'=>$payload['discount_type'],'discount_value'=>$payload['discount_value'],'min_subtotal_minor'=>$payload['min_subtotal_minor'],'max_discount_minor'=>null,'usage_limit'=>($v=(int)$request->request->get('usage_limit',0))>0?$v:null,'usage_count'=>0,'per_customer_limit'=>($v2=(int)$request->request->get('per_customer_limit',0))>0?$v2:null,'priority'=>(int)$request->request->get('priority',100),'stop_processing'=>$request->request->has('stop_processing')?1:0,'conditions_json'=>$payload['conditions_json'],'starts_at'=>$this->dateOrNull((string)$request->request->get('starts_at')),'ends_at'=>$this->dateOrNull((string)$request->request->get('ends_at')),'created_at'=>$now,'updated_at'=>$now]);
                $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.aktsiiu_stvoreno_vona_odrazu_vrakhovuietsia_serverny'));
            }catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.aktsiiu_ne_vdalosia_stvoryty'));}
            return $this->redirectToRoute('admin_commerce_promotions');
        }
        $rows=$this->db->fetchAllAssociative('SELECT * FROM mc_promotion WHERE store_id=? ORDER BY status DESC,priority,id DESC',[$context->storeId]);
        $markets=$this->db->fetchAllAssociative('SELECT id,name,default_currency FROM mc_market WHERE store_id=? ORDER BY id',[$context->storeId]);
        $categoryOptions=array_map(static fn(array $r):array=>['value'=>(string)$r['id'],'label'=>(string)$r['name']],$this->db->fetchAllAssociative("SELECT c.id,COALESCE(t.name,CONCAT('#',c.id)) name FROM mc_category c LEFT JOIN mc_category_translation t ON t.category_id=c.id AND t.locale=? WHERE c.status='active' ORDER BY c.parent_id,c.sort_order,c.id",[$context->locale]));
        $groupCodes=array_unique(array_merge(['default','vip','wholesale'],array_map('strval',$this->db->fetchFirstColumn("SELECT DISTINCT customer_group_code FROM mc_customer WHERE customer_group_code IS NOT NULL AND customer_group_code<>''"))));
        $groupOptions=array_map(static fn(string $g):array=>['value'=>$g,'label'=>$g],array_values($groupCodes));
        return $this->render('@storefront/admin/commerce/promotions.html.twig',['promotions'=>$rows,'markets'=>$markets,'category_options'=>$categoryOptions,'group_options'=>$groupOptions,'stats'=>$this->promotionStats($context->storeId)]);
    }

    /** @return array<int,array{discount_minor:int,orders:int}> what every promotion has given away so far */
    private function promotionStats(int $storeId):array
    {
        $out=[];
        try{foreach($this->db->fetchAllAssociative('SELECT r.promotion_id,COUNT(*) orders,COALESCE(SUM(r.discount_minor),0) discount_minor FROM mc_promotion_redemption r JOIN mc_promotion p ON p.id=r.promotion_id WHERE p.store_id=? GROUP BY r.promotion_id',[$storeId]) as $r)$out[(int)$r['promotion_id']]=['discount_minor'=>(int)$r['discount_minor'],'orders'=>(int)$r['orders']];}catch(\Throwable){}
        return $out;
    }

    private function promotionOptions(int $storeId,string $locale):array
    {
        $categoryOptions=array_map(static fn(array $r):array=>['value'=>(string)$r['id'],'label'=>(string)$r['name']],$this->db->fetchAllAssociative("SELECT c.id,COALESCE(t.name,CONCAT('#',c.id)) name FROM mc_category c LEFT JOIN mc_category_translation t ON t.category_id=c.id AND t.locale=? WHERE c.status='active' ORDER BY c.parent_id,c.sort_order,c.id",[$locale]));
        $groupCodes=array_unique(array_merge(['default','vip','wholesale'],array_map('strval',$this->db->fetchFirstColumn("SELECT DISTINCT customer_group_code FROM mc_customer WHERE customer_group_code IS NOT NULL AND customer_group_code<>''"))));
        return [$categoryOptions,array_map(static fn(string $g):array=>['value'=>$g,'label'=>$g],array_values($groupCodes))];
    }

    #[Route('/admin/commerce/promotions/{id}/edit', name:'admin_commerce_promotion_edit', methods:['GET','POST'], requirements:['id'=>'\d+'])]
    public function editPromotion(int $id,Request $request): Response
    {
        $context=$this->contexts->resolve($request);
        $row=$this->db->fetchAssociative('SELECT * FROM mc_promotion WHERE id=? AND store_id=?',[$id,$context->storeId]); if(!is_array($row))throw $this->createNotFoundException();
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('promotion_edit_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            try{
                $payload=$this->promotionPayload($request);
                $this->db->update('mc_promotion',['name'=>$payload['name'],'code'=>$payload['code'],'trigger_type'=>$payload['trigger_type'],'discount_type'=>$payload['discount_type'],'discount_value'=>$payload['discount_value'],'min_subtotal_minor'=>$payload['min_subtotal_minor'],'usage_limit'=>($v=(int)$request->request->get('usage_limit',0))>0?$v:null,'per_customer_limit'=>($v2=(int)$request->request->get('per_customer_limit',0))>0?$v2:null,'priority'=>(int)$request->request->get('priority',100),'stop_processing'=>$request->request->has('stop_processing')?1:0,'conditions_json'=>$payload['conditions_json'],'starts_at'=>$this->dateOrNull((string)$request->request->get('starts_at')),'ends_at'=>$this->dateOrNull((string)$request->request->get('ends_at')),'updated_at'=>$this->now()],['id'=>$id]);
                $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('admin.promo.saved'));
            }catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('admin.promo.save_failed'));}
            return $this->redirectToRoute('admin_commerce_promotion_edit',['id'=>$id]);
        }
        $cond=json_decode((string)$row['conditions_json'],true);$cond=is_array($cond)?$cond:[];
        $local=static fn(?string $v):string=>$v===null?'':(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->format('Y-m-d\TH:i');
        $promo=$row+[
            'discount_value'=>number_format(((int)$row['discount_value'])/100,2,'.',''),'min_subtotal'=>number_format(((int)$row['min_subtotal_minor'])/100,2,'.',''),
            'starts_at'=>$local($row['starts_at']),'ends_at'=>$local($row['ends_at']),'product_ids'=>implode(', ',array_map('intval',(array)($cond['product_ids']??[]))),
            'category_ids'=>array_map('strval',(array)($cond['category_ids']??[])),'customer_groups'=>array_map('strval',(array)($cond['customer_groups']??[])),'market_ids'=>array_map('strval',(array)($cond['market_ids']??[])),
            'stop_processing'=>(bool)$row['stop_processing'],'usage_limit'=>(int)($row['usage_limit']??0),'per_customer_limit'=>(int)($row['per_customer_limit']??0),
        ];
        try{
            $s=$this->db->fetchAssociative('SELECT COUNT(*) used,COALESCE(SUM(r.discount_minor),0) discount_minor,COUNT(DISTINCT COALESCE(r.customer_id,r.email_normalized)) customers,MAX(r.created_at) last,COALESCE(SUM(o.total_minor),0) revenue_minor FROM mc_promotion_redemption r LEFT JOIN mc_sales_order o ON o.id=r.order_id WHERE r.promotion_id=?',[$id])?:[];
            $redemptions=$this->db->fetchAllAssociative('SELECT r.created_at,r.coupon_code,r.discount_minor,o.order_number,o.public_id order_public_id FROM mc_promotion_redemption r LEFT JOIN mc_sales_order o ON o.id=r.order_id WHERE r.promotion_id=? ORDER BY r.id DESC LIMIT 50',[$id]);
            foreach($redemptions as &$r){$r['order_public_id']=$r['order_public_id']!==null?\Symfony\Component\Uid\Uuid::fromBinary((string)$r['order_public_id'])->toRfc4122():null;}unset($r);
        }catch(\Throwable){$s=[];$redemptions=[];}
        $stats=['used'=>(int)($s['used']??0),'discount_minor'=>(int)($s['discount_minor']??0),'customers'=>(int)($s['customers']??0),'last'=>$s['last']??null,'revenue_minor'=>(int)($s['revenue_minor']??0)];
        [$categoryOptions,$groupOptions]=$this->promotionOptions($context->storeId,$context->locale);
        return $this->render('@storefront/admin/commerce/promotion_edit.html.twig',['promo'=>$promo,'stats'=>$stats,'redemptions'=>$redemptions,'markets'=>$this->db->fetchAllAssociative('SELECT id,name,default_currency FROM mc_market WHERE store_id=? ORDER BY id',[$context->storeId]),'category_options'=>$categoryOptions,'group_options'=>$groupOptions]);
    }

    #[Route('/admin/commerce/promotions/{id}/duplicate', name:'admin_commerce_promotion_duplicate', methods:['POST'], requirements:['id'=>'\d+'])]
    public function duplicatePromotion(int $id,Request $request): Response
    {
        $context=$this->contexts->resolve($request); if(!$this->isCsrfTokenValid('promotion_toggle_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        $row=$this->db->fetchAssociative('SELECT * FROM mc_promotion WHERE id=? AND store_id=?',[$id,$context->storeId]); if(!is_array($row))throw $this->createNotFoundException();
        unset($row['id']);$now=$this->now();$row['public_id']=$this->ids->binary();$row['name']=mb_substr((string)$row['name'].' (copy)',0,190);$row['code']=null;$row['trigger_type']='automatic';$row['status']='disabled';$row['usage_count']=0;$row['created_at']=$now;$row['updated_at']=$now;
        $this->db->insert('mc_promotion',$row);$newId=(int)$this->db->lastInsertId();
        return $this->redirectToRoute('admin_commerce_promotion_edit',['id'=>$newId]);
    }

    #[Route('/admin/commerce/promotions/{id}/delete', name:'admin_commerce_promotion_delete', methods:['POST'], requirements:['id'=>'\d+'])]
    public function deletePromotion(int $id,Request $request): Response
    {
        $context=$this->contexts->resolve($request); if(!$this->isCsrfTokenValid('promotion_toggle_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        try{$this->db->delete('mc_promotion',['id'=>$id,'store_id'=>$context->storeId]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('admin.promo.deleted'));}
        catch(\Throwable){$this->db->update('mc_promotion',['status'=>'disabled','updated_at'=>$this->now()],['id'=>$id,'store_id'=>$context->storeId]);$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('admin.promo.delete_blocked'));}
        return $this->redirectToRoute('admin_commerce_promotions');
    }

    /** @return array{name:string,code:?string,trigger_type:string,discount_type:string,discount_value:int,min_subtotal_minor:int,conditions_json:string} the validated fields of the promotion form */
    private function promotionPayload(Request $request):array
    {
        $name=trim((string)$request->request->get('name'));$trigger=(string)$request->request->get('trigger_type','automatic');$code=$trigger==='coupon'?mb_strtoupper(trim((string)$request->request->get('code'))):null;$type=(string)$request->request->get('discount_type','percent');
        if($name===''||mb_strlen($name)>190)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.vkazhit_nazvu_aktsii')); if(!in_array($trigger,['automatic','coupon'],true))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.nekorektnyi_typ_aktsii')); if($trigger==='coupon'&&($code===''||!preg_match('/^[A-Z0-9_-]{3,64}$/',$code)))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.promokod_3_64_symvoly_a_z_0_9_abo')); if(!in_array($type,['percent','fixed'],true))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.nekorektnyi_typ_znyzhky'));
        $valueRaw=str_replace(',','.',trim((string)$request->request->get('discount_value','0'))); if(!is_numeric($valueRaw)||(float)$valueRaw<=0)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.vkazhit_rozmir_znyzhky')); $discountValue=$type==='percent'?(int)round(min(100,(float)$valueRaw)*100):(int)round((float)$valueRaw*100);
        $minRaw=str_replace(',','.',trim((string)$request->request->get('min_subtotal','0')));$minMinor=max(0,(int)round(((float)$minRaw)*100));
        $conditions=['product_ids'=>array_values(array_filter(array_map('intval',preg_split('/[\s,;]+/',(string)$request->request->get('product_ids',''),-1,PREG_SPLIT_NO_EMPTY)?:[]))),'category_ids'=>array_values(array_filter(array_map('intval',$request->request->all('category_ids')))),'market_ids'=>array_values(array_filter(array_map('intval',$request->request->all('market_ids')))),'customer_groups'=>array_values(array_unique(array_filter(array_map(static fn($v)=>strtolower(trim((string)$v)),$request->request->all('customer_groups')))))];
        return ['name'=>$name,'code'=>$code,'trigger_type'=>$trigger,'discount_type'=>$type,'discount_value'=>$discountValue,'min_subtotal_minor'=>$minMinor,'conditions_json'=>json_encode($conditions,JSON_THROW_ON_ERROR)];
    }

    #[Route('/admin/commerce/promotions/{id}/toggle', name:'admin_commerce_promotion_toggle', methods:['POST'], requirements:['id'=>'\\d+'])]
    public function togglePromotion(int $id,Request $request): Response
    {
        $context=$this->contexts->resolve($request); if(!$this->isCsrfTokenValid('promotion_toggle_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        $status=$this->db->fetchOne('SELECT status FROM mc_promotion WHERE id=? AND store_id=?',[$id,$context->storeId]); if($status===false)throw $this->createNotFoundException();
        $this->db->update('mc_promotion',['status'=>$status==='active'?'disabled':'active','updated_at'=>$this->now()],['id'=>$id,'store_id'=>$context->storeId]);
        return $this->redirectToRoute('admin_commerce_promotions');
    }

    #[Route('/admin/commerce/customers', name:'admin_commerce_customers', methods:['GET','POST'])]
    public function customers(Request $request): Response
    {
        $this->contexts->resolve($request);
        if($request->isMethod('POST')){
            $id=(int)$request->request->get('id');if(!$this->isCsrfTokenValid('customer_group_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            $group=strtolower(trim((string)$request->request->get('customer_group_code','default')));if(preg_match('/^[a-z0-9_-]{1,64}$/D',$group)!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.nekorektnyi_kod_hrupy'));
            $this->db->update('mc_customer',['customer_group_code'=>$group,'updated_at'=>$this->now()],['id'=>$id]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.hrupu_pokuptsia_onovleno'));return $this->redirectToRoute('admin_commerce_customers');
        }
        $q=trim((string)$request->query->get('q',''));$group=trim((string)$request->query->get('group',''));$status=(string)$request->query->get('status','');$sort=(string)$request->query->get('sort','new');
        $where=['1=1'];$params=[];
        if($q!==''){$like='%'.addcslashes($q,'%_\\').'%';$where[]='(c.display_name LIKE ? OR c.email LIKE ? OR c.phone_e164 LIKE ?)';array_push($params,$like,$like,$like);}
        if($group!==''){$where[]='c.customer_group_code=?';$params[]=$group;}
        if(in_array($status,['active','blocked'],true)){$where[]='c.status=?';$params[]=$status;}
        $orderBy=match($sort){'spent'=>'spent_minor DESC','orders'=>'orders_count DESC','last'=>'last_order_at IS NULL, last_order_at DESC',default=>'c.id DESC'};
        $rows=$this->db->fetchAllAssociative("SELECT c.id,c.display_name,c.email,c.phone_e164,c.status,c.customer_group_code,c.created_at,(SELECT COUNT(*) FROM mc_sales_order o WHERE o.customer_id=c.id AND o.status NOT IN ('cancelled','expired')) orders_count,(SELECT COALESCE(SUM(o.total_minor),0) FROM mc_sales_order o WHERE o.customer_id=c.id AND o.status NOT IN ('cancelled','expired')) spent_minor,(SELECT o.currency FROM mc_sales_order o WHERE o.customer_id=c.id ORDER BY o.id DESC LIMIT 1) currency,(SELECT MAX(o.created_at) FROM mc_sales_order o WHERE o.customer_id=c.id) last_order_at FROM mc_customer c WHERE ".implode(' AND ',$where)." ORDER BY $orderBy LIMIT 2000",$params);
        $groups=array_map('strval',$this->db->fetchFirstColumn("SELECT DISTINCT customer_group_code FROM mc_customer WHERE customer_group_code IS NOT NULL AND customer_group_code<>'' ORDER BY 1"));
        $totals=$this->db->fetchAssociative("SELECT COUNT(*) total,SUM(status='blocked') blocked,SUM(created_at>=UTC_TIMESTAMP(6)-INTERVAL 30 DAY) new30 FROM mc_customer")?:[];
        return $this->render('@storefront/admin/commerce/customers.html.twig',['rows'=>$rows,'filters'=>['q'=>$q,'group'=>$group,'status'=>$status,'sort'=>$sort],'groups'=>$groups,'totals'=>$totals]);
    }

    #[Route('/admin/commerce/inquiries', name:'admin_commerce_inquiries', methods:['GET','POST'])]
    public function inquiries(Request $request): Response
    {
        $context=$this->contexts->resolve($request);
        if($request->isMethod('POST')){
            $id=(int)$request->request->get('id');
            if(!$this->isCsrfTokenValid('inquiry_update_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            $status=(string)$request->request->get('status','new');if(!in_array($status,['new','in_progress','resolved','closed'],true))$status='new';
            $this->db->update('mc_customer_inquiry',['status'=>$status,'admin_note'=>mb_substr(trim((string)$request->request->get('admin_note')),0,4000),'updated_at'=>$this->now()],['id'=>$id,'store_id'=>$context->storeId]);
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.zvernennia_onovleno'));return $this->redirectToRoute('admin_commerce_inquiries');
        }
        $q=trim((string)$request->query->get('q',''));$status=(string)$request->query->get('status','');$type=(string)$request->query->get('type','');
        $where=['i.store_id=?'];$params=[$context->locale,$context->storeId];
        if(in_array($status,['new','in_progress','resolved','closed'],true)){$where[]='i.status=?';$params[]=$status;}
        if($type!==''&&preg_match('/^[a-z_]{1,32}$/D',$type)===1){$where[]='i.inquiry_type=?';$params[]=$type;}
        if($q!==''){$like='%'.addcslashes($q,'%_\\').'%';$where[]='(i.customer_name LIKE ? OR i.email LIKE ? OR i.phone LIKE ? OR i.message LIKE ?)';array_push($params,$like,$like,$like,$like);}
        $rows=$this->db->fetchAllAssociative("SELECT i.*,i.customer_name AS name,pt.name product_name FROM mc_customer_inquiry i LEFT JOIN mc_product_translation pt ON pt.product_id=i.product_id AND pt.store_id=i.store_id AND pt.locale=? WHERE ".implode(' AND ',$where)." ORDER BY FIELD(i.status,'new','in_progress','resolved','closed'),i.id DESC LIMIT 500",$params);
        $counts=$this->db->fetchAllKeyValue('SELECT status,COUNT(*) FROM mc_customer_inquiry WHERE store_id=? GROUP BY status',[$context->storeId]);
        $types=array_map('strval',$this->db->fetchFirstColumn('SELECT DISTINCT inquiry_type FROM mc_customer_inquiry WHERE store_id=? ORDER BY 1',[$context->storeId]));
        return $this->render('@storefront/admin/commerce/inquiries.html.twig',['rows'=>$rows,'counts'=>$counts,'types'=>$types,'filters'=>['q'=>$q,'status'=>$status,'type'=>$type]]);
    }

    #[Route('/admin/commerce/import-export', name:'admin_commerce_import_export', methods:['GET','POST'])]
    public function importExport(Request $request): Response
    {
        $context=$this->contexts->resolve($request);$report=null;
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('catalog_import',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            $file=$request->files->get('catalog_csv'); if(!$file instanceof UploadedFile||!$file->isValid()){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.oberit_korektnyi_csv_fail'));return $this->redirectToRoute('admin_commerce_import_export');}
            if($file->getSize()>20*1024*1024){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.csv_bilshyi_za_20_mb'));return $this->redirectToRoute('admin_commerce_import_export');}
            try{$report=$request->request->get('mode')==='apply'?$this->csv->import($file->getPathname(),$context->storeId,$context->marketId,$context->locale):$this->csv->preview($file->getPathname(),$context->storeId,$context->marketId,$context->locale);}
            catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.csv_ne_vdalosia_obrobyty'));}
        }
        return $this->render('@storefront/admin/commerce/import_export.html.twig',['report'=>$report]);
    }

    #[Route('/admin/commerce/import-wizard', name:'admin_commerce_import_wizard', methods:['GET','POST'])]
    public function importWizard(Request $request): Response
    {
        $context=$this->contexts->resolve($request);$inspection=null;$report=null;
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('universal_import',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            try{
                $action=(string)$request->request->get('action','inspect');
                if($action==='inspect'){
                    $file=$request->files->get('import_file');if(!$file instanceof UploadedFile||!$file->isValid())throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('import.universal.error.choose_file'));
                    $inspection=$this->universalImport->stage($file->getPathname(),$file->getClientOriginalName());$profileId=(int)$request->request->get('profile_id',0);if($profileId>0){$saved=$this->universalImport->profileMapping($context->storeId,$profileId);if($saved!==[])$inspection['suggestions']=array_replace($inspection['suggestions'],$saved);}
                }else{
                    $mapping=$request->request->all('mapping');
                    $report=$this->universalImport->process((string)$request->request->get('token'),is_array($mapping)?$mapping:[],$context->storeId,$context->marketId,$context->locale,$request->request->get('mode')==='apply');$profileName=trim((string)$request->request->get('profile_name'));if($profileName!==''&&is_array($mapping))$this->universalImport->saveProfile($context->storeId,$profileName,(string)$request->request->get('source_format','csv'),$mapping);
                }
            }catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('import.universal.error.process'));}
        }
        return $this->render('@storefront/admin/commerce/import_wizard.html.twig',['inspection'=>$inspection,'report'=>$report,'profiles'=>$this->universalImport->profiles($context->storeId),'target_fields'=>['sku'=>'import.universal.field.sku','name'=>'import.universal.field.name','price'=>'import.universal.field.price','currency'=>'import.universal.field.currency','stock_quantity'=>'import.universal.field.stock','unit_code'=>'import.universal.field.unit','gtin'=>'import.universal.field.gtin','mpn'=>'import.universal.field.mpn','brand'=>'import.universal.field.brand','category_ids'=>'import.universal.field.categories','slug'=>'import.universal.field.slug','short_description'=>'import.universal.field.short_description','description'=>'import.universal.field.description','status'=>'import.universal.field.status','product_type'=>'import.universal.field.product_type']]);
    }

    #[Route('/admin/commerce/export/products.csv', name:'admin_commerce_export_products', methods:['GET'])]
    public function exportProducts(Request $request): Response
    {
        $context=$this->contexts->resolve($request);$csv=$this->csv->exportProducts($context->storeId,$context->marketId,$context->locale);
        return new Response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="products-'.gmdate('Ymd-His').'.csv"','X-Content-Type-Options'=>'nosniff']);
    }

    #[Route('/admin/commerce/export/products-multilingual.csv', name:'admin_commerce_export_products_multilingual', methods:['GET'])]
    public function exportProductsMultilingual(Request $request): Response
    {
        $context=$this->contexts->resolve($request);$csv=$this->csv->exportProductsMultilingual($context->storeId,$context->marketId);
        return new Response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="products-multilingual-'.gmdate('Ymd-His').'.csv"','X-Content-Type-Options'=>'nosniff']);
    }

    #[Route('/admin/commerce/products/bulk', name:'admin_commerce_products_bulk', methods:['POST'])]
    public function bulkProducts(Request $request): Response
    {
        $context=$this->contexts->resolve($request); if(!$this->isCsrfTokenValid('products_bulk',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        $ids=array_values(array_filter(array_map('strval',$request->request->all('product_ids'))));$action=(string)$request->request->get('action');$status=match($action){'publish'=>'published','draft'=>'draft','archive'=>'archived',default=>null};
        if($action==='duplicate'){
            $made=0;$failed=0;
            foreach(array_slice($ids,0,50) as $id){try{$this->catalogMaintenance->duplicateProductDraft($context->storeId,$context->marketId,$context->locale,$id);++$made;}catch(\Throwable){++$failed;}}
            $this->addFlash($failed>0?'warning':'success',\Commerce\Core\I18n\CanonicalUiText::get('admin.catalog.bulk.duplicated',['count'=>$made,'failed'=>$failed]));
            return $this->redirectToRoute('admin_catalog_products');
        }
        if($status===null){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.oberit_masovu_diiu'));return $this->redirectToRoute('admin_catalog_products');}
        try{$r=$this->bulk->setStatus($context->storeId,$context->marketId,$context->locale,$ids,$status);$this->addFlash($r['failed']>0?'error':'success',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.onovleno_d_z_d_tovariv_pomylok_d'),$r['updated'],$r['requested'],$r['failed']));foreach($r['errors'] as $e)$this->addFlash('error',$e);}catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_catalog_products');
    }


    #[Route('/admin/commerce/campaigns', name:'admin_commerce_campaigns', methods:['GET','POST'])]
    public function campaigns(Request $request): Response
    {
        $context=$this->contexts->resolve($request);
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('campaign_send',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            try{$r=$this->campaigns->createAndEnqueue($context->storeId,(string)$request->request->get('subject'),(string)$request->request->get('body'),(string)$request->request->get('segment','all_subscribers'),5000,(string)$request->request->get('format','text'),(string)$request->request->get('send_at',''),(string)$request->request->get('locale',''));$this->addFlash('success',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.kampaniiu_dodano_v_cherhu_dlia_d_pidtverdzhenykh_pid'),$r['recipients']));}
            catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.kampaniiu_ne_vdalosia_stvoryty'));}
            return $this->redirectToRoute('admin_commerce_campaigns');
        }
        $rows=$this->db->fetchAllAssociative('SELECT id,subject,segment_code,status,recipient_count,created_at,enqueued_at,send_at,locale FROM mc_marketing_campaign WHERE store_id=? ORDER BY id DESC LIMIT 100',[$context->storeId]);
        foreach($rows as &$row){$delivery=$this->db->fetchAllKeyValue('SELECT status,COUNT(*) FROM mc_notification_outbox WHERE dedupe_key LIKE ? GROUP BY status',['campaign:'.(int)$row['id'].':%']);$row['sent']=(int)($delivery['sent']??0);$row['failed']=(int)($delivery['failed']??0)+(int)($delivery['dead']??0);$row['pending']=(int)($delivery['pending']??0)+(int)($delivery['processing']??0);}unset($row);
        $locales=$this->db->fetchAllAssociative('SELECT locale,COUNT(*) n FROM mc_marketing_subscriber WHERE store_id=? AND status=\'active\' GROUP BY locale ORDER BY n DESC',[$context->storeId]);
        $subscribers=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_marketing_subscriber WHERE store_id=? AND status='active'",[$context->storeId]);
        return $this->render('@storefront/admin/commerce/campaigns.html.twig',['locales'=>$locales,'rows'=>$rows,'subscribers'=>$subscribers,'segments'=>$this->segments->labels(),'templates'=>$this->campaignTemplates->list($context->storeId)]);
    }

    #[Route('/admin/commerce/notifications/maintenance', name:'admin_commerce_notifications_maintenance', methods:['POST'])]
    public function notificationsMaintenance(Request $request): Response
    {
        $this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('notification_maintenance',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        $action=(string)$request->request->get('action','');
        if($action==='retry_failed'){
            $n=$this->db->executeStatement("UPDATE mc_notification_outbox SET status='pending',attempts=0,last_error=NULL,locked_at=NULL,lock_token=NULL,available_at=UTC_TIMESTAMP(6) WHERE status='failed'");
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('admin.notifications.retried',['n'=>(string)$n]));
        }elseif($action==='purge_sent'){
            $days=max(7,min(3650,$request->request->getInt('days',30)));
            $n=$this->db->executeStatement("DELETE FROM mc_notification_outbox WHERE status='sent' AND sent_at<?",[gmdate('Y-m-d H:i:s',time()-$days*86400)]);
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('admin.notifications.purged',['n'=>(string)$n]));
        }
        return $this->redirectToRoute('admin_commerce_notifications');
    }

    #[Route('/admin/commerce/notifications', name:'admin_commerce_notifications', methods:['GET','POST'])]
    public function notifications(Request $request): Response
    {
        $this->contexts->resolve($request);
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('notification_test',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            try{$channel=NotificationChannel::from((string)$request->request->get('channel'));$recipient=trim((string)$request->request->get('recipient'));$message=new NotificationMessage('admin.test',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.testove_povidomlennia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.kanal_spovishchen_nalashtovano_tse_testove_povidomle'),[],'generic');$this->notifications->enqueue($channel,$message,$recipient);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.testove_povidomlennia_dodano_v_cherhu_rezultat_ziavy'));}catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
            return $this->redirectToRoute('admin_commerce_notifications');
        }
$status=(string)$request->query->get('status',''); $channel=(string)$request->query->get('channel',''); $search=trim((string)$request->query->get('q',''));
        $where=['1=1']; $params=[];
        if(in_array($status,['pending','processing','sent','failed'],true)){$where[]='status=?';$params[]=$status;}
        if(in_array($channel,['email','telegram','sms','web_push'],true)){$where[]='channel=?';$params[]=$channel;}
        if($search!==''){$where[]='(recipient LIKE ? OR notification_type LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
        $rows=$this->db->fetchAllAssociative('SELECT id,channel,notification_type,recipient,status,attempts,last_error,created_at,sent_at FROM mc_notification_outbox WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 200',$params);
        $counts=$this->db->fetchAllKeyValue('SELECT status,COUNT(*) FROM mc_notification_outbox GROUP BY status');
        $channels=$this->db->fetchFirstColumn('SELECT DISTINCT channel FROM mc_notification_outbox ORDER BY channel');
        return $this->render('@storefront/admin/commerce/notifications.html.twig',['rows'=>$rows,'counts'=>$counts,'status'=>$status,'channel'=>$channel,'q'=>$search,'channels'=>$channels]);
    }

    private function now(): string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
    private function dateOrNull(string $value): ?string{$value=trim($value);if($value==='')return null;try{return(new DateTimeImmutable($value,new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.commerceoperationsadmincontroller.nekorektna_data_aktsii'));}}
}
