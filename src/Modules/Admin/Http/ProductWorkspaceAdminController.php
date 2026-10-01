<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Catalog\Infrastructure\DbalCatalogAdminQuery;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductWorkspaceAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly DbalCatalogAdminQuery $query, private readonly ProductWriter $writer, private readonly Connection $db, private readonly \Commerce\Modules\Catalog\Application\ProductBulkEditor $editor, private readonly \Commerce\Modules\Admin\Undo\AdminUndoService $undo, private readonly \Commerce\Modules\Catalog\Application\SkuGenerator $skus) {}

    #[Route('/admin/catalog/products/bulk-edit', name:'admin_catalog_products_bulk_edit', methods:['GET','POST'])]
    public function bulkEdit(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);$ids=$this->ids($request->isMethod('POST')?$request->request->all('product_ids'):(array)$request->query->all('id'));
        if($request->isMethod('POST')&&$request->request->has('bulk_editor_save')){
            if(!$this->isCsrfTokenValid('products_bulk_editor',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            $result=$this->editor->apply($ctx->storeId,$ctx->marketId,$ctx->locale,$ctx->currency,$request->request->all('rows'));
            foreach($result['failed'] as $failedId){$this->addFlash('error',$failedId.': '.\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
            if($result['previous']!==[]&&$this->adminId()!==null){$this->undo->remember($ctx->storeId,(int)$this->adminId(),'product_bulk',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('admin.undo.product_bulk'),count($result['previous'])),['rows'=>$result['previous']]);}
            $this->addFlash($result['failed']?'warning':'success',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.productworkspaceadmincontroller.zberezheno_d_tovariv_pomylok_d'),$result['ok'],count($result['failed'])));return $this->redirectToRoute('admin_catalog_products');
        }
        if($ids===[]){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.productworkspaceadmincontroller.oberit_khocha_b_odyn_tovar'));return $this->redirectToRoute('admin_catalog_products');}
        $rows=[];foreach(array_slice($ids,0,100) as $id){try{$rows[]=$this->query->productForEdit($ctx->storeId,$ctx->marketId,$ctx->locale,$id);}catch(\Throwable){}}
        return $this->render('@storefront/admin/catalog/bulk_edit.html.twig',['rows'=>$rows]);
    }


    /** Next product code by the template (saves the template when one is sent). Answers JSON. */
    #[Route('/admin/catalog/sku/next', name:'admin_catalog_sku_next', methods:['POST'])]
    public function skuNext(Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('admin_sku',(string)$request->request->get('_token')))return $this->json(['ok'=>false],403);
        $template=(string)$request->request->get('template','');
        if(trim($template)!=='')$template=$this->skus->saveTemplate($template);
        $sku=$this->skus->next($template);
        return $this->json(['ok'=>$sku!=='','sku'=>$sku,'template'=>$template!==''?$template:$this->skus->template()]);
    }

    /** Changes only the price of one product from the list (Enter or leaving the field). Answers JSON. */
    #[Route('/admin/catalog/products/{publicId}/quick-price', name:'admin_catalog_product_quick_price', methods:['POST'])]
    public function quickPrice(string $publicId, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $ctx=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('admin_quick_price',(string)$request->request->get('_token')))return new \Symfony\Component\HttpFoundation\JsonResponse(['ok'=>false],403);
        $raw=str_replace(',','.',trim((string)$request->request->get('price','')));
        if(preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D',$raw)!==1)return new \Symfony\Component\HttpFoundation\JsonResponse(['ok'=>false,'error'=>'format'],422);
        $result=$this->editor->apply($ctx->storeId,$ctx->marketId,$ctx->locale,$ctx->currency,[$publicId=>['price'=>$raw]]);
        if($result['failed']!==[])return new \Symfony\Component\HttpFoundation\JsonResponse(['ok'=>false,'error'=>'save'],422);
        if($result['previous']!==[]&&$this->adminId()!==null){$this->undo->remember($ctx->storeId,(int)$this->adminId(),'product_bulk',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('admin.undo.product_bulk'),1),['rows'=>$result['previous']]);}
        return new \Symfony\Component\HttpFoundation\JsonResponse(['ok'=>true,'price'=>number_format((float)$raw,2,'.','')]);
    }


    #[Route('/admin/catalog/products/{publicId}/preview', name:'admin_catalog_product_preview', methods:['GET'])]
    public function preview(string $publicId, Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        try{$product=$this->query->productForEdit($ctx->storeId,$ctx->marketId,$ctx->locale,$publicId);}catch(\Throwable){return new Response(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.productworkspaceadmincontroller.section_class_admin_preview_card_p_tovar_ne_znaideno'),404);}
        $images=[];
        $images=$this->db->fetchAllAssociative("SELECT ma.storage_key,COALESCE(pm.alt_text,sma.alt_text) alt_text,sma.title FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id LEFT JOIN mc_store_media_asset sma ON sma.asset_id=ma.id AND sma.store_id=? WHERE pm.product_id=? AND pm.variant_id IS NULL ORDER BY (pm.role='primary') DESC,pm.sort_order,pm.media_asset_id LIMIT 4",[$ctx->storeId,(int)$product['id']]);
        foreach($images as &$image){$image['url']='/media/'.ltrim((string)$image['storage_key'],'/');}unset($image);
        $product['price_display']=$product['amount_minor']===null?'—':number_format(((int)$product['amount_minor'])/100,2,',',' ').' '.($product['currency']??$ctx->currency);
        return $this->render('@storefront/admin/catalog/_product_preview.html.twig',['product'=>$product,'images'=>$images]);
    }

    #[Route('/admin/catalog/products/views/save', name:'admin_catalog_saved_view_save', methods:['POST'])]
    public function saveView(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);if(!$this->isCsrfTokenValid('admin_saved_view_save',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();$name=trim(strip_tags((string)$request->request->get('name','')));if($name===''||mb_strlen($name)>190){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.vkazhit_nazvu_predstavlennia'));return $this->redirectToRoute('admin_catalog_products');}
        $filters=['search'=>mb_substr(trim((string)$request->request->get('search','')),0,190)];$allowed=['name','sku','status','price','type','quality'];$columns=array_values(array_intersect($allowed,array_map('strval',(array)$request->request->all('columns'))));if($columns===[])$columns=$allowed;$this->db->insert('mc_admin_saved_view',['store_id'=>$ctx->storeId,'admin_id'=>$this->adminId(),'entity_type'=>'products','name'=>$name,'filters_json'=>json_encode($filters,JSON_THROW_ON_ERROR),'columns_json'=>json_encode($columns,JSON_THROW_ON_ERROR),'is_default'=>0,'created_at'=>gmdate('Y-m-d H:i:s.u'),'updated_at'=>gmdate('Y-m-d H:i:s.u')]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.productworkspaceadmincontroller.predstavlennia_zberezheno'));return $this->redirectToRoute('admin_catalog_products',['search'=>$filters['search'],'columns'=>$columns]);
    }

    #[Route('/admin/catalog/products/views/{id}/delete', name:'admin_catalog_saved_view_delete', methods:['POST'], requirements:['id'=>'\\d+'])]
    public function deleteView(int $id,Request $request): Response{$ctx=$this->contexts->resolve($request);if(!$this->isCsrfTokenValid('admin_saved_view_delete_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();$this->db->executeStatement('DELETE FROM mc_admin_saved_view WHERE id=? AND store_id=? AND entity_type=? AND (admin_id=? OR admin_id IS NULL)',[$id,$ctx->storeId,'products',$this->adminId()]);return $this->redirectToRoute('admin_catalog_products');}

    private function adminId(): ?int{$u=$this->getUser();return $u instanceof AdminUser?$u->id:null;}
    /** @param array<mixed> $values @return list<string> */ private function ids(array $values): array{$out=[];foreach($values as $v){$s=trim((string)$v);if(preg_match('/^[0-9a-fA-F-]{36}$/D',$s))$out[$s]=true;}return array_keys($out);}
}
