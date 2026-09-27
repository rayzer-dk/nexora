<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class CatalogMetadataAdminController extends AbstractController
{
    private const ATTRIBUTE_TYPES = ['text','number','decimal','boolean'];

    public function __construct(
        private readonly Connection $db,
        private readonly AdminContextResolver $contexts,
        private readonly PublicIdFactory $ids,
        private readonly SeoUrlManager $seo,
    ) {}

    #[Route('/admin/catalog/metadata', name:'admin_catalog_metadata', methods:['GET'])]
    public function index(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        $brands=$this->db->fetchAllAssociative(
            "SELECT b.id,b.name,b.website_url,sb.status,sb.sort_order,COALESCE(sr.slug,bt.slug) slug,bt.description,bt.meta_title,bt.meta_description
             FROM mc_brand b JOIN mc_store_brand sb ON sb.brand_id=b.id AND sb.store_id=?
             LEFT JOIN mc_brand_translation bt ON bt.brand_id=b.id AND bt.store_id=sb.store_id AND bt.locale=?
             LEFT JOIN mc_seo_route sr ON sr.store_id=sb.store_id AND sr.locale=? AND sr.entity_type='brand' AND sr.entity_public_id=b.public_id
             ORDER BY sb.sort_order,b.name,b.id",
            [$ctx->storeId,$ctx->locale,$ctx->locale],
        );
        $attributes=$this->db->fetchAllAssociative(
            "SELECT ad.id,ad.code,ad.data_type,ad.filterable,ad.comparable,ad.sort_order,COALESCE(at.name,ad.code) name,at.unit_label,
                    (SELECT COUNT(*) FROM mc_product_attribute_value pav WHERE pav.attribute_id=ad.id) usage_count
             FROM mc_attribute_definition ad
             LEFT JOIN mc_attribute_translation at ON at.attribute_id=ad.id AND at.locale=?
             ORDER BY ad.sort_order,ad.id",
            [$ctx->locale],
        );
        foreach($attributes as &$row){$row['usage_count']=(int)$row['usage_count'];}unset($row);
        return $this->render('@storefront/admin/catalog/metadata.html.twig',[
            'brands'=>$brands,'attributes'=>$attributes,'locale'=>$ctx->locale,'attribute_types'=>self::ATTRIBUTE_TYPES,
        ]);
    }

    #[Route('/admin/catalog/metadata/brands/create', name:'admin_catalog_brand_create', methods:['POST'])]
    public function brandCreate(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('catalog_metadata_brand_create',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        try{
            $name=$this->name((string)$request->request->get('name',''));
            $normalized=mb_strtolower($name,'UTF-8');
            $website=$this->website((string)$request->request->get('website_url',''));
            $sort=max(-100000,min(100000,(int)$request->request->get('sort_order',0)));
            $now=gmdate('Y-m-d H:i:s.u');
            $this->db->transactional(function(Connection $db)use($ctx,$name,$normalized,$website,$sort,$now,$request):void{
                $brandId=$db->fetchOne('SELECT id FROM mc_brand WHERE normalized_name=? LIMIT 1',[$normalized]);
                if($brandId===false){$db->insert('mc_brand',['public_id'=>$this->ids->binary(),'name'=>$name,'normalized_name'=>$normalized,'website_url'=>$website,'logo_media_id'=>null,'created_at'=>$now,'updated_at'=>$now]);$brandId=(int)$db->lastInsertId();}
                else{$brandId=(int)$brandId;}
                $db->executeStatement("INSERT INTO mc_store_brand(store_id,brand_id,status,sort_order) VALUES(?,?,'active',?) ON DUPLICATE KEY UPDATE status='active',sort_order=VALUES(sort_order)",[$ctx->storeId,$brandId,$sort]);
                $this->upsertBrandTranslation($db,$brandId,$ctx->storeId,$ctx->locale,$request);
            });
            $brand=$this->db->fetchAssociative('SELECT b.id,b.public_id,b.name FROM mc_brand b JOIN mc_store_brand sb ON sb.brand_id=b.id AND sb.store_id=? WHERE b.normalized_name=? LIMIT 1',[$ctx->storeId,$normalized]);
            if(is_array($brand))$this->syncBrandSeo((int)$brand['id'],(string)$brand['public_id'],(string)$brand['name'],$ctx->storeId,$ctx->locale,(string)$request->request->get('slug',''));
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.brend_zberezheno'));
        }catch(\Throwable $e){$this->addFlash('error',$e instanceof \InvalidArgumentException||$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.brend_ne_vdalosia_zberehty'));}
        return $this->redirectToRoute('admin_catalog_metadata');
    }

    #[Route('/admin/catalog/metadata/brands/{id}/update', name:'admin_catalog_brand_update', methods:['POST'], requirements:['id'=>'\\d+'])]
    public function brandUpdate(int $id, Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('catalog_metadata_brand_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        try{
            $exists=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_store_brand WHERE store_id=? AND brand_id=?',[$ctx->storeId,$id]);if($exists!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.brend_ne_znaideno_v_tsomu_mahazyni'));
            $name=$this->name((string)$request->request->get('name',''));$normalized=mb_strtolower($name,'UTF-8');$website=$this->website((string)$request->request->get('website_url',''));$status=(string)$request->request->get('status','active');if(!in_array($status,['active','disabled'],true))throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.nekorektnyi_status_brendu'));$sort=max(-100000,min(100000,(int)$request->request->get('sort_order',0)));
            $this->db->transactional(function(Connection $db)use($ctx,$id,$name,$normalized,$website,$status,$sort,$request):void{$duplicate=$db->fetchOne('SELECT id FROM mc_brand WHERE normalized_name=? AND id<>? LIMIT 1',[$normalized,$id]);if($duplicate!==false)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.brend_z_takoiu_nazvoiu_vzhe_isnuie'));$db->update('mc_brand',['name'=>$name,'normalized_name'=>$normalized,'website_url'=>$website,'updated_at'=>gmdate('Y-m-d H:i:s.u')],['id'=>$id]);$db->update('mc_store_brand',['status'=>$status,'sort_order'=>$sort],['store_id'=>$ctx->storeId,'brand_id'=>$id]);$this->upsertBrandTranslation($db,$id,$ctx->storeId,$ctx->locale,$request);});
            $publicBinary=$this->db->fetchOne('SELECT public_id FROM mc_brand WHERE id=? LIMIT 1',[$id]);if(is_string($publicBinary))$this->syncBrandSeo($id,$publicBinary,$name,$ctx->storeId,$ctx->locale,(string)$request->request->get('slug',''));
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.brend_onovleno'));
        }catch(\Throwable $e){$this->addFlash('error',$e instanceof \InvalidArgumentException||$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.brend_ne_vdalosia_onovyty'));}
        return $this->redirectToRoute('admin_catalog_metadata');
    }

    #[Route('/admin/catalog/metadata/attributes/create', name:'admin_catalog_attribute_create', methods:['POST'])]
    public function attributeCreate(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('catalog_metadata_attribute_create',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        try{$code=$this->code((string)$request->request->get('code',''));$name=$this->name((string)$request->request->get('name',''));$type=$this->type((string)$request->request->get('data_type','text'));$sort=max(-100000,min(100000,(int)$request->request->get('sort_order',0)));$unit=$this->unit((string)$request->request->get('unit_label',''));$this->db->transactional(function(Connection $db)use($ctx,$code,$name,$type,$sort,$unit,$request):void{$db->insert('mc_attribute_definition',['public_id'=>$this->ids->binary(),'code'=>$code,'data_type'=>$type,'filterable'=>$request->request->getBoolean('filterable')?1:0,'comparable'=>$request->request->getBoolean('comparable')?1:0,'sort_order'=>$sort]);$id=(int)$db->lastInsertId();$db->insert('mc_attribute_translation',['attribute_id'=>$id,'locale'=>$ctx->locale,'name'=>$name,'unit_label'=>$unit]);});$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.kharakterystyku_stvoreno'));}catch(\Throwable $e){$this->addFlash('error',$e instanceof \InvalidArgumentException||$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.kharakterystyku_ne_vdalosia_stvoryty'));}
        return $this->redirectToRoute('admin_catalog_metadata');
    }

    #[Route('/admin/catalog/metadata/attributes/{id}/update', name:'admin_catalog_attribute_update', methods:['POST'], requirements:['id'=>'\\d+'])]
    public function attributeUpdate(int $id,Request $request):Response
    {
        $ctx=$this->contexts->resolve($request);if(!$this->isCsrfTokenValid('catalog_metadata_attribute_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        try{$exists=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_attribute_definition WHERE id=?',[$id]);if($exists!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.kharakterystyku_ne_znaideno'));$code=$this->code((string)$request->request->get('code',''));$name=$this->name((string)$request->request->get('name',''));$type=$this->type((string)$request->request->get('data_type','text'));$sort=max(-100000,min(100000,(int)$request->request->get('sort_order',0)));$unit=$this->unit((string)$request->request->get('unit_label',''));$this->db->transactional(function(Connection $db)use($ctx,$id,$code,$name,$type,$sort,$unit,$request):void{$duplicate=$db->fetchOne('SELECT id FROM mc_attribute_definition WHERE code=? AND id<>? LIMIT 1',[$code,$id]);if($duplicate!==false)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.kharakterystyka_z_takym_kodom_vzhe_isnuie'));$db->update('mc_attribute_definition',['code'=>$code,'data_type'=>$type,'filterable'=>$request->request->getBoolean('filterable')?1:0,'comparable'=>$request->request->getBoolean('comparable')?1:0,'sort_order'=>$sort],['id'=>$id]);$db->executeStatement('INSERT INTO mc_attribute_translation(attribute_id,locale,name,unit_label) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),unit_label=VALUES(unit_label)',[$id,$ctx->locale,$name,$unit]);});$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.kharakterystyku_onovleno'));}catch(\Throwable $e){$this->addFlash('error',$e instanceof \InvalidArgumentException||$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.kharakterystyku_ne_vdalosia_onovyty'));}
        return $this->redirectToRoute('admin_catalog_metadata');
    }

    #[Route('/admin/catalog/metadata/attributes/{id}/delete', name:'admin_catalog_attribute_delete', methods:['POST'], requirements:['id'=>'\\d+'])]
    public function attributeDelete(int $id,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('catalog_metadata_attribute_delete_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        $used=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_product_attribute_value WHERE attribute_id=?',[$id]);if($used>0){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.kharakterystyka_vykorystovuietsia_tovaramy_spochatku'));return $this->redirectToRoute('admin_catalog_metadata');}$this->db->delete('mc_attribute_definition',['id'=>$id]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.nevykorystanu_kharakterystyku_vydaleno'));return $this->redirectToRoute('admin_catalog_metadata');
    }

    private function upsertBrandTranslation(Connection $db,int $brandId,int $storeId,string $locale,Request $request):void
    {
        $slug=trim((string)$request->request->get('slug',''));if($slug!==''&&mb_strlen($slug)>255)throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.nekorektnyi_slug_brendu'));$slug=$slug!==''?$slug:null;
        $description=mb_substr(trim((string)$request->request->get('description','')),0,20000);$title=mb_substr(trim((string)$request->request->get('meta_title','')),0,255);$meta=mb_substr(trim((string)$request->request->get('meta_description','')),0,500);
        $db->executeStatement('INSERT INTO mc_brand_translation(brand_id,store_id,locale,slug,description,meta_title,meta_description) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE slug=VALUES(slug),description=VALUES(description),meta_title=VALUES(meta_title),meta_description=VALUES(meta_description)',[$brandId,$storeId,$locale,$slug,$description!==''?$description:null,$title!==''?$title:null,$meta!==''?$meta:null]);
    }
    private function syncBrandSeo(int $brandId,string $publicBinary,string $name,int $storeId,string $locale,string $requestedSlug):void
    {
        $publicId=Uuid::fromBinary($publicBinary)->toRfc4122();
        $route=$this->seo->ensureForCreatedEntity($storeId,$locale,SeoEntityType::Brand,$publicId,$name,trim($requestedSlug)!==''?$requestedSlug:null);
        if(trim($requestedSlug)!==''&&trim($requestedSlug)!==$route->slug)$route=$this->seo->changeSlug($route,$requestedSlug);
        $this->db->update('mc_brand_translation',['slug'=>$route->slug],['brand_id'=>$brandId,'store_id'=>$storeId,'locale'=>$locale]);
    }
    private function name(string $v):string{$v=mb_substr(trim(strip_tags($v)),0,190);if($v==='')throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.nazva_oboviazkova'));return $v;}
    private function code(string $v):string{$v=mb_strtolower(trim($v),'UTF-8');if(preg_match('/^[a-z][a-z0-9_]{1,126}$/D',$v)!==1)throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.kod_latynytsia_tsyfry_ta_vid_2_do_127_symvoliv'));return $v;}
    private function type(string $v):string{if(!in_array($v,self::ATTRIBUTE_TYPES,true))throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.nekorektnyi_typ_kharakterystyky'));return $v;}
    private function unit(string $v):?string{$v=mb_substr(trim(strip_tags($v)),0,64);return $v===''?null:$v;}
    private function website(string $v):?string{$v=trim($v);if($v==='')return null;if(mb_strlen($v)>2048||filter_var($v,FILTER_VALIDATE_URL)===false||!in_array(parse_url($v,PHP_URL_SCHEME),['http','https'],true))throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogmetadataadmincontroller.nekorektnyi_url_saitu_brendu'));return $v;}
}
