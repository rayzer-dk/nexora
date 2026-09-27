<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Feeds\Application\FeedStorageService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FeedAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly Connection $db,
        private readonly FeedStorageService $feeds,
        private readonly string $publicBaseUrl,
    ) {}

    #[Route('/admin/commerce/feeds', name:'admin_commerce_feeds', methods:['GET','POST'])]
    public function index(Request $request): Response
    {
        $context=$this->contexts->resolve($request);
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('feed_category_mapping',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            try{
                $platform=(string)$request->request->get('platform');$categoryId=(int)$request->request->get('category_id');$externalId=trim((string)$request->request->get('external_category_id'));$externalName=trim((string)$request->request->get('external_category_name'));
                if(!in_array($platform,['rozetka','prom'],true))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.pidtrymuietsia_mapping_dlia_rozetka_abo_prom_ua'));
                if($categoryId<1||(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_category WHERE id=? AND store_id=?',[$categoryId,$context->storeId])!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.katehoriia_ne_nalezhyt_mahazynu'));
                if($externalId===''||mb_strlen($externalId)>190)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.vkazhit_id_katehorii_maidanchyka'));
                $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
                $this->db->executeStatement('INSERT INTO mc_feed_category_mapping (store_id,platform,category_id,external_category_id,external_category_name,updated_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE external_category_id=VALUES(external_category_id),external_category_name=VALUES(external_category_name),updated_at=VALUES(updated_at)',[$context->storeId,$platform,$categoryId,$externalId,$externalName!==''?$externalName:null,$now]);
                $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.mapping_katehorii_zberezheno'));
            }catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.mapping_ne_zberezheno'));}
            return $this->redirectToRoute('admin_commerce_feeds');
        }

        $store=$this->db->fetchAssociative('SELECT code,name,default_currency FROM mc_store WHERE id=?',[$context->storeId]) ?: [];
        $platforms=['google'=>'Google Merchant XML','meta'=>'Meta / Facebook CSV','pinterest'=>'Pinterest CSV','tiktok'=>'TikTok Catalog CSV','rozetka'=>'Rozetka XML','prom'=>'Prom.ua YML','csv'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.universalnyi_csv'),'json'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.universalnyi_json'),'agentic'=>'AI / Agentic Commerce JSONL'];
        $rows=[];
        foreach($platforms as $code=>$label){
            $latest=$this->feeds->latest((string)($store['code']??'store'),$code,$context->locale);$meta=$latest['meta']??[];
            $rows[]=['code'=>$code,'label'=>$label,'count'=>$meta['count']??null,'skipped'=>$meta['skipped']??null,'warnings'=>$meta['warnings']??[],'generated_at'=>$meta['generated_at']??null,'bytes'=>$meta['bytes']??null,'url'=>rtrim($this->publicBaseUrl,'/').'/feeds/'.rawurlencode((string)($store['code']??'store')).'/'.$code.'?locale='.rawurlencode($context->locale)];
        }
        $categories=$this->db->fetchAllAssociative('SELECT c.id,ct.name FROM mc_category c JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=? WHERE c.status=? ORDER BY ct.name',[$context->storeId,$context->locale,'active']);
        $mappings=$this->db->fetchAllAssociative('SELECT m.platform,m.category_id,m.external_category_id,m.external_category_name,ct.name category_name FROM mc_feed_category_mapping m JOIN mc_category_translation ct ON ct.category_id=m.category_id AND ct.store_id=m.store_id AND ct.locale=? WHERE m.store_id=? ORDER BY m.platform,ct.name',[$context->locale,$context->storeId]);
        return $this->render('@storefront/admin/commerce/feeds.html.twig',['rows'=>$rows,'store'=>$store,'locale'=>$context->locale,'categories'=>$categories,'mappings'=>$mappings]);
    }

    #[Route('/admin/commerce/feeds/{platform}/generate', name:'admin_commerce_feed_generate', methods:['POST'], requirements:['platform'=>'google|meta|pinterest|tiktok|rozetka|prom|csv|json|agentic'])]
    public function generate(string $platform,Request $request): Response
    {
        $context=$this->contexts->resolve($request);if(!$this->isCsrfTokenValid('feed_generate_'.$platform,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();$store=$this->db->fetchAssociative('SELECT code,default_currency FROM mc_store WHERE id=?',[$context->storeId])?:[];
        try{$r=$this->feeds->generate((string)$store['code'],$platform,$context->storeId,$context->marketId,$context->locale,(string)$store['default_currency'],$request->request->getBoolean('in_stock'));$this->addFlash('success',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.s_zhenerovano_d_pozytsii_propushcheno_d'),$platform,(int)$r['meta']['count'],(int)$r['meta']['skipped']));foreach(array_slice((array)$r['meta']['warnings'],0,3) as $warning)$this->addFlash('warning',$warning);}catch(\Throwable $e){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.feed_ne_zhenerovano').\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        return $this->redirectToRoute('admin_commerce_feeds');
    }

    #[Route('/admin/commerce/feeds/{platform}/preview', name:'admin_commerce_feed_preview', methods:['GET'], requirements:['platform'=>'google|meta|pinterest|tiktok|rozetka|prom|csv|json|agentic'])]
    public function preview(string $platform,Request $request): Response
    {
        $context=$this->contexts->resolve($request);$store=$this->db->fetchAssociative('SELECT code,default_currency FROM mc_store WHERE id=?',[$context->storeId])?:[];$stored=$this->feeds->latest((string)$store['code'],$platform,$context->locale);if($stored===null){$this->addFlash('warning',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.feedadmincontroller.spochatku_zheneruite_feed'));return $this->redirectToRoute('admin_commerce_feeds');}
        $response=new \Symfony\Component\HttpFoundation\BinaryFileResponse($stored['path']);$response->headers->set('Content-Type',(string)$stored['meta']['content_type']);$response->headers->set('Content-Disposition','inline; filename="feed-'.$platform.'.'.$stored['meta']['extension'].'"');return $response;
    }
}
