<?php

declare(strict_types=1);
namespace Commerce\Modules\Compare\Http;

use Commerce\Modules\Storefront\Infrastructure\DbalStorefrontCatalogQuery;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class ProductCompareController extends AbstractController
{
    private const KEY='storefront_compare';
    public function __construct(private readonly StorefrontContextResolver $contexts,private readonly DbalStorefrontCatalogQuery $catalog){}

    #[Route('/compare',name:'product_compare',methods:['GET'],priority:100)]
    public function index(Request $request):Response
    {
        $ctx=$this->contexts->resolve($request);$ids=$this->ids($request,$ctx->storeId);$products=[];$validIds=[];
        foreach($ids as $id){try{$p=$this->catalog->productByPublicId($ctx,$id);if(is_array($p)){$products[]=$p;$validIds[]=$id;}}catch(\Throwable){}}
        if($validIds!==$ids)$this->save($request,$ctx->storeId,$validIds);
        $names=[];foreach($products as $p){foreach((array)($p['attributes']??[]) as $a){$n=(string)($a['name']??'');if($n!==''&&!in_array($n,$names,true))$names[]=$n;}}
        return $this->render('@storefront/compare/index.html.twig',['page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.compare.http.productcomparecontroller.porivniannia_tovariv'),'store_name'=>$ctx->storeName,'products'=>$products,'attribute_names'=>$names,'seo_head'=>['robots'=>'noindex,follow']]);
    }

    #[Route('/compare/{product}/add',name:'product_compare_add',methods:['POST'],priority:110)]
    public function add(string $product,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('compare_'.$product,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        try{Uuid::fromString($product);}catch(\Throwable){throw $this->createNotFoundException();}
        $ctx=$this->contexts->resolve($request);
        try{$resolved=$this->catalog->productByPublicId($ctx,$product);}catch(\Throwable){$resolved=null;}
        if(!is_array($resolved))throw $this->createNotFoundException();
        $ids=$this->ids($request,$ctx->storeId);$ids=array_values(array_filter($ids,static fn(string $v):bool=>$v!==$product));$ids[]=$product;if(count($ids)>4)$ids=array_slice($ids,-4);$this->save($request,$ctx->storeId,$ids);
        return $this->redirect($this->back($request));
    }

    #[Route('/compare/{product}/remove',name:'product_compare_remove',methods:['POST'],priority:110)]
    public function remove(string $product,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('compare_remove_'.$product,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);$this->save($request,$ctx->storeId,array_values(array_filter($this->ids($request,$ctx->storeId),static fn(string $v):bool=>$v!==$product)));return $this->redirectToRoute('product_compare');
    }

    #[Route('/compare/clear',name:'product_compare_clear',methods:['POST'],priority:110)]
    public function clear(Request $request):Response
    {
        if(!$this->isCsrfTokenValid('compare_clear',(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        $ctx=$this->contexts->resolve($request);$this->save($request,$ctx->storeId,[]);return $this->redirectToRoute('product_compare');
    }

    /** @return list<string> */
    private function ids(Request $r,int $storeId):array{$all=$r->getSession()->get(self::KEY,[]);$ids=is_array($all)&&isset($all[$storeId])&&is_array($all[$storeId])?$all[$storeId]:[];return array_values(array_filter(array_map('strval',$ids),static function(string $id):bool{try{Uuid::fromString($id);return true;}catch(\Throwable){return false;}}));}
    /** @param list<string> $ids */
    private function save(Request $r,int $storeId,array $ids):void{$all=$r->getSession()->get(self::KEY,[]);if(!is_array($all))$all=[];$all[$storeId]=$ids;$r->getSession()->set(self::KEY,$all);}
    private function back(Request $r):string{$u=(string)$r->headers->get('referer','/compare');$p=parse_url($u,PHP_URL_PATH);return is_string($p)&&str_starts_with($p,'/')?$p:'/compare';}
}
