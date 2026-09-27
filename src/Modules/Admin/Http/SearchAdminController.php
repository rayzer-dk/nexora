<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Search\Application\SearchSynonymService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $context, private readonly SearchSynonymService $synonyms, private readonly Connection $db) {}

    #[Route('/admin/catalog/search', name: 'admin_catalog_search', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->context->resolve($request);
        $boosts=[];$relations=[];
        try{$boosts=$this->db->fetchAllAssociative("SELECT sb.id,sb.query_text,sb.weight,sb.status,pt.name,v.sku FROM mc_search_boost sb JOIN mc_product p ON p.id=sb.product_id JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=? JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0 WHERE sb.store_id=? AND sb.locale=? ORDER BY sb.status DESC,sb.query_text,sb.weight DESC",[$context->storeId,$context->locale,$context->storeId,$context->locale]);}catch(\Throwable){}
        try{$relations=$this->db->fetchAllAssociative("SELECT r.product_id,r.related_product_id,r.relation_type,r.sort_order,pt.name product_name,pv.sku product_sku,rpt.name related_name,rv.sku related_sku FROM mc_product_relation r JOIN mc_product p ON p.id=r.product_id JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=? JOIN mc_product_variant pv ON pv.product_id=p.id AND pv.sort_order=0 JOIN mc_product rp ON rp.id=r.related_product_id JOIN mc_product_translation rpt ON rpt.product_id=rp.id AND rpt.store_id=? AND rpt.locale=? JOIN mc_product_variant rv ON rv.product_id=rp.id AND rv.sort_order=0 JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE r.relation_type IN ('related','complementary') ORDER BY r.product_id,r.relation_type,r.sort_order LIMIT 250",[$context->storeId,$context->locale,$context->storeId,$context->locale,$context->storeId]);}catch(\Throwable){}
        $recommendationSettings=['related_mode'=>'hybrid','complementary_mode'=>'hybrid','auto_limit'=>8,'in_stock_only'=>0]; try{$row=$this->db->fetchAssociative('SELECT related_mode,complementary_mode,auto_limit,in_stock_only FROM mc_recommendation_setting WHERE store_id=?',[$context->storeId]);if(is_array($row))$recommendationSettings=$row;}catch(\Throwable){} return $this->render('@storefront/admin/catalog/search.html.twig', ['groups'=>$this->synonyms->groups($context->storeId,$context->locale),'locale'=>$context->locale,'boosts'=>$boosts,'relations'=>$relations,'recommendation_settings'=>$recommendationSettings]);
    }

    #[Route('/admin/catalog/search/synonyms/create', name: 'admin_catalog_search_synonym_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_search_synonym_create',(string)$request->request->get('_token'))) {$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));return $this->redirectToRoute('admin_catalog_search');}
        try{$context=$this->context->resolve($request);$this->synonyms->createGroup($context->storeId,$context->locale,(string)$request->request->get('label',''),(string)$request->request->get('terms',''));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.hrupu_synonimiv_stvoreno'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.synonimy_ne_vdalosia_zberehty_poshuk_prodovzhuie_pra'));}
        return $this->redirectToRoute('admin_catalog_search');
    }

    #[Route('/admin/catalog/search/synonyms/{id}/delete', name: 'admin_catalog_search_synonym_delete', methods: ['POST'])]
    public function delete(int $id,Request $request): Response
    {
        if(!$this->isCsrfTokenValid('admin_search_synonym_delete_'.$id,(string)$request->request->get('_token')))throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        try{$context=$this->context->resolve($request);$this->synonyms->deleteGroup($context->storeId,$context->locale,$id);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.hrupu_synonimiv_vydaleno'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        return $this->redirectToRoute('admin_catalog_search');
    }

    #[Route('/admin/catalog/search/boost/create', name:'admin_catalog_search_boost_create', methods:['POST'])]
    public function createBoost(Request $request): Response
    {
        $ctx=$this->context->resolve($request); if(!$this->isCsrfTokenValid('admin_search_boost_create',(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        $query=$this->term((string)$request->request->get('query_text',''));$sku=trim((string)$request->request->get('sku',''));$weight=max(1,min(1000,(int)$request->request->get('weight',100)));
        if($query===''||mb_strlen($query)>190){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.vkazhit_poshukovu_frazu_do_190_symvoliv'));return $this->redirectToRoute('admin_catalog_search');}
        $productId=$this->productBySku($ctx->storeId,$sku);if($productId===null){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.tovar_iz_takym_sku_ne_znaideno'));return $this->redirectToRoute('admin_catalog_search');}
        try{$this->db->insert('mc_search_boost',['store_id'=>$ctx->storeId,'locale'=>$ctx->locale,'query_text'=>$query,'query_hash'=>hash('sha256',$query,true),'product_id'=>$productId,'weight'=>$weight,'status'=>'active','created_at'=>gmdate('Y-m-d H:i:s.u'),'updated_at'=>gmdate('Y-m-d H:i:s.u')]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.pidsylennia_poshuku_dodano'));}catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.take_pidsylennia_vzhe_isnuie_abo_tablytsia_shche_ne_'));}
        return $this->redirectToRoute('admin_catalog_search');
    }

    #[Route('/admin/catalog/search/boost/{id}/delete', name:'admin_catalog_search_boost_delete', methods:['POST'], requirements:['id'=>'\\d+'])]
    public function deleteBoost(int $id,Request $request): Response
    {
        $ctx=$this->context->resolve($request);if(!$this->isCsrfTokenValid('admin_search_boost_delete_'.$id,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$this->db->delete('mc_search_boost',['id'=>$id,'store_id'=>$ctx->storeId]);return $this->redirectToRoute('admin_catalog_search');
    }


    #[Route('/admin/catalog/search/recommendations/settings', name:'admin_catalog_recommendation_settings', methods:['POST'])]
    public function recommendationSettings(Request $request): Response
    {
        $ctx=$this->context->resolve($request);
        if(!$this->isCsrfTokenValid('admin_recommendation_settings',(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        $allowed=['manual','hybrid','auto'];
        $related=(string)$request->request->get('related_mode','hybrid');
        $complementary=(string)$request->request->get('complementary_mode','hybrid');
        if(!in_array($related,$allowed,true))$related='hybrid';
        if(!in_array($complementary,$allowed,true))$complementary='hybrid';
        $data=['related_mode'=>$related,'complementary_mode'=>$complementary,'auto_limit'=>max(1,min(8,(int)$request->request->get('auto_limit',8))),'in_stock_only'=>$request->request->getBoolean('in_stock_only'),'updated_at'=>gmdate('Y-m-d H:i:s.u')];
        if((bool)$this->db->fetchOne('SELECT 1 FROM mc_recommendation_setting WHERE store_id=?',[$ctx->storeId]))$this->db->update('mc_recommendation_setting',$data,['store_id'=>$ctx->storeId]);else $this->db->insert('mc_recommendation_setting',['store_id'=>$ctx->storeId]+$data);
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.nalashtuvannia_rekomendatsii_zberezheno'));
        return $this->redirectToRoute('admin_catalog_search');
    }
    #[Route('/admin/catalog/search/relation/create', name:'admin_catalog_relation_create', methods:['POST'])]
    public function createRelation(Request $request): Response
    {
        $ctx=$this->context->resolve($request);if(!$this->isCsrfTokenValid('admin_relation_create',(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        $source=$this->productBySku($ctx->storeId,trim((string)$request->request->get('source_sku','')));$target=$this->productBySku($ctx->storeId,trim((string)$request->request->get('target_sku','')));$type=(string)$request->request->get('relation_type','complementary');if(!in_array($type,['related','complementary'],true))$type='complementary';
        if($source===null||$target===null||$source===$target){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.perevirte_sku_obydva_tovary_maiut_isnuvaty_i_buty_ri'));return $this->redirectToRoute('admin_catalog_search');}
        try{$this->db->insert('mc_product_relation',['product_id'=>$source,'related_product_id'=>$target,'relation_type'=>$type,'sort_order'=>max(0,min(999,(int)$request->request->get('sort_order',100))),'created_at'=>gmdate('Y-m-d H:i:s.u')]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.rekomendatsiiu_dodano'));}catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.searchadmincontroller.tsei_zviazok_uzhe_isnuie'));}
        return $this->redirectToRoute('admin_catalog_search');
    }

    #[Route('/admin/catalog/search/relation/delete', name:'admin_catalog_relation_delete', methods:['POST'])]
    public function deleteRelation(Request $request): Response
    {
        $ctx=$this->context->resolve($request);if(!$this->isCsrfTokenValid('admin_relation_delete',(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$source=(int)$request->request->get('product_id');$target=(int)$request->request->get('related_product_id');$type=(string)$request->request->get('relation_type');
        $this->db->executeStatement('DELETE r FROM mc_product_relation r JOIN mc_store_product sp ON sp.product_id=r.product_id AND sp.store_id=? WHERE r.product_id=? AND r.related_product_id=? AND r.relation_type=?',[$ctx->storeId,$source,$target,$type]);return $this->redirectToRoute('admin_catalog_search');
    }

    private function productBySku(int $storeId,string $sku): ?int{if($sku===''||mb_strlen($sku)>190)return null;$id=$this->db->fetchOne('SELECT p.id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? JOIN mc_product_variant v ON v.product_id=p.id WHERE v.sku=? ORDER BY v.sort_order,v.id LIMIT 1',[$storeId,$sku]);return $id===false?null:(int)$id;}
    private function term(string $value): string{$v=mb_strtolower(trim($value),'UTF-8');return preg_replace('/\\s+/u',' ',$v)??$v;}
}
