<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Catalog\Infrastructure\DbalCatalogAdminQuery;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductWorkspaceAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly DbalCatalogAdminQuery $query, private readonly ProductWriter $writer, private readonly Connection $db) {}

    #[Route('/admin/catalog/products/bulk-edit', name:'admin_catalog_products_bulk_edit', methods:['GET','POST'])]
    public function bulkEdit(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);$ids=$this->ids($request->isMethod('POST')?$request->request->all('product_ids'):(array)$request->query->all('id'));
        if($request->isMethod('POST')&&$request->request->has('bulk_editor_save')){
            if(!$this->isCsrfTokenValid('products_bulk_editor',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            $rows=$request->request->all('rows');$ok=0;$failed=0;
            foreach($rows as $publicId=>$data){if(!is_array($data))continue;try{$p=$this->query->productForEdit($ctx->storeId,$ctx->marketId,$ctx->locale,(string)$publicId);$this->writer->update(new UpdateProductCommand(productId:(int)$p['id'],storeId:$ctx->storeId,marketId:$ctx->marketId,locale:$ctx->locale,name:(string)($data['name']??$p['name']),sku:(string)($data['sku']??$p['sku']),priceMinor:$this->minor((string)($data['price']??'0')),currency:(string)($p['currency']??$ctx->currency),stockQuantity:$this->qty((string)($data['stock']??$p['stock_quantity']??'0')),unitCode:(string)($p['sale_unit_code']??'pcs'),categoryIds:$p['category_ids'],manualSlug:(string)($p['slug']??''),shortDescription:(string)($p['short_description']??''),description:(string)($p['description']??''),gtin:$p['gtin']!==null?(string)$p['gtin']:null,mpn:$p['mpn']!==null?(string)$p['mpn']:null,status:in_array((string)($data['status']??''),['draft','published','archived'],true)?(string)$data['status']:(string)$p['status'],brandId:$p['brand_id']!==null?(int)$p['brand_id']:null,purchaseMode:(string)($p['purchase_mode']??'auto'),purchaseButtonLabel:$p['purchase_button_label']!==null?(string)$p['purchase_button_label']:null,purchaseEtaText:$p['purchase_eta_text']!==null?(string)$p['purchase_eta_text']:null));$ok++;}catch(\Throwable $e){$failed++;$this->addFlash('error',(string)$publicId.': '.\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}}
            $this->addFlash($failed?'warning':'success',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.productworkspaceadmincontroller.zberezheno_d_tovariv_pomylok_d'),$ok,$failed));return $this->redirectToRoute('admin_catalog_products');
        }
        if($ids===[]){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.productworkspaceadmincontroller.oberit_khocha_b_odyn_tovar'));return $this->redirectToRoute('admin_catalog_products');}
        $rows=[];foreach(array_slice($ids,0,100) as $id){try{$rows[]=$this->query->productForEdit($ctx->storeId,$ctx->marketId,$ctx->locale,$id);}catch(\Throwable){}}
        return $this->render('@storefront/admin/catalog/bulk_edit.html.twig',['rows'=>$rows]);
    }


    #[Route('/admin/catalog/products/{publicId}/preview', name:'admin_catalog_product_preview', methods:['GET'])]
    public function preview(string $publicId, Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        try{$product=$this->query->productForEdit($ctx->storeId,$ctx->marketId,$ctx->locale,$publicId);}catch(\Throwable){return new Response(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.productworkspaceadmincontroller.section_class_admin_preview_card_p_tovar_ne_znaideno'),404);}
        $images=[];
        try{$images=$this->db->fetchAllAssociative('SELECT ma.storage_key,ma.alt_text,ma.title FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_id WHERE pm.product_id=? ORDER BY pm.is_primary DESC,pm.sort_order,pm.id LIMIT 4',[(int)$product['id']]);}catch(\Throwable){}
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
    private function minor(string $v): int{$v=str_replace(',','.',trim($v));if(!preg_match('/^\\d{1,9}(?:\\.\\d{1,2})?$/D',$v))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektna_tsina'));return(int)round((float)$v*100);}
    private function qty(string $v): string{$v=str_replace(',','.',trim($v));if(!preg_match('/^\\d{1,12}(?:\\.\\d{1,6})?$/D',$v))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.importexport.application.catalogcsvservice.nekorektnyi_zalyshok'));return number_format((float)$v,6,'.','');}
}
