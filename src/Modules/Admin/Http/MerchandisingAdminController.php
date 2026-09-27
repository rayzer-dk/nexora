<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MerchandisingAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly Connection $db) {}

    #[Route('/admin/catalog/merchandising', name:'admin_catalog_merchandising', methods:['GET','POST'])]
    public function index(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request); $categoryId=max(0,$request->query->getInt('category', (int)$request->request->get('category_id',0)));
        if($request->isMethod('POST')){
            if(!$this->isCsrfTokenValid('category_merchandising',(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            $categoryId=(int)$request->request->get('category_id');
            if(!(bool)$this->db->fetchOne('SELECT 1 FROM mc_store_category WHERE store_id=? AND category_id=?',[$ctx->storeId,$categoryId]))throw $this->createNotFoundException();
            $mode=(string)$request->request->get('mode','manual'); if(!in_array($mode,['manual','newest','price_desc','rating','stock'],true))$mode='manual';
            $ids=array_values(array_unique(array_filter(array_map('intval',preg_split('/\s*,\s*/',(string)$request->request->get('product_order',''),-1,PREG_SPLIT_NO_EMPTY)?:[]))));
            $this->db->transactional(function(Connection $db) use($ctx,$categoryId,$mode,$ids):void{
                foreach($ids as $i=>$productId){$db->executeStatement('UPDATE mc_product_category pc JOIN mc_store_product sp ON sp.product_id=pc.product_id AND sp.store_id=? SET pc.sort_order=? WHERE pc.category_id=? AND pc.product_id=?',[$ctx->storeId,$i*10,$categoryId,$productId]);}
                $now=gmdate('Y-m-d H:i:s.u');
                $data=['mode'=>$mode,'pinned_json'=>'[]','rules_json'=>json_encode(['mode'=>$mode],JSON_THROW_ON_ERROR),'updated_by'=>null,'updated_at'=>$now];
                if((bool)$db->fetchOne('SELECT 1 FROM mc_category_merchandising WHERE category_id=? AND store_id=?',[$categoryId,$ctx->storeId]))$db->update('mc_category_merchandising',$data,['category_id'=>$categoryId,'store_id'=>$ctx->storeId]);
                else $db->insert('mc_category_merchandising',['category_id'=>$categoryId,'store_id'=>$ctx->storeId]+$data);
            });
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.merchandisingadmincontroller.merchandaizynh_katehorii_zberezheno'));
            return $this->redirectToRoute('admin_catalog_merchandising',['category'=>$categoryId]);
        }
        $categories=$this->db->fetchAllAssociative('SELECT c.id,ct.name FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=? ORDER BY ct.name',[$ctx->storeId,$ctx->storeId,$ctx->locale]);
        $products=[];$mode='manual';
        if($categoryId>0){$products=$this->db->fetchAllAssociative("SELECT p.id,pt.name,v.sku,pc.sort_order,p.status FROM mc_product_category pc JOIN mc_product p ON p.id=pc.product_id JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=? JOIN mc_product_variant v ON v.product_id=p.id AND v.sort_order=0 WHERE pc.category_id=? ORDER BY pc.sort_order,p.id",[$ctx->storeId,$ctx->storeId,$ctx->locale,$categoryId]);$mode=(string)($this->db->fetchOne('SELECT mode FROM mc_category_merchandising WHERE category_id=? AND store_id=?',[$categoryId,$ctx->storeId])?:'manual');}
        return $this->render('@storefront/admin/catalog/merchandising.html.twig',['categories'=>$categories,'category_id'=>$categoryId,'products'=>$products,'mode'=>$mode]);
    }
}
