<?php

declare(strict_types=1);
namespace Commerce\Modules\Cart\Http;

use Commerce\Modules\Cart\Application\CartMutationService;
use Commerce\Modules\Cart\Application\SavedCartService;
use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SavedCartController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts,private readonly CartMutationService $carts,private readonly SavedCartService $saved){}
    #[Route('/account/saved-carts',name:'customer_saved_carts',methods:['GET'],priority:120)] public function index(Request $r):Response{$c=$this->contexts->resolve($r);$u=$this->customer();return$this->render('@storefront/account/saved_carts.html.twig',['page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.savedcartcontroller.zberezheni_koshyky'),'store_name'=>$c->storeName,'saved_carts'=>$this->saved->list($c->storeId,$u->id()),'seo_head'=>['robots'=>'noindex,nofollow']]);}
    #[Route('/cart/save',name:'customer_saved_cart_save',methods:['POST'],priority:120)] public function save(Request $r):Response{if(!$this->isCsrfTokenValid('saved_cart_save',(string)$r->request->get('_token')))throw$this->createAccessDeniedException();$c=$this->contexts->resolve($r);$u=$this->customer();$cart=$this->carts->open($c,$r->cookies->get('mc_cart'));$this->saved->bindCustomer($cart['id'],$c->storeId,$u->id());try{$this->saved->save($cart['id'],$c,$u->id(),(string)$r->request->get('name',''));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.savedcartcontroller.koshyk_zberezheno'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}$response=$this->redirectToRoute('customer_saved_carts');$this->cookie($response,$r,$cart);return$response;}
    #[Route('/account/saved-carts/{id}/restore',name:'customer_saved_cart_restore',methods:['POST'],priority:120)] public function restore(string $id,Request $r):Response{if(!$this->isCsrfTokenValid('saved_cart_restore_'.$id,(string)$r->request->get('_token')))throw$this->createAccessDeniedException();$c=$this->contexts->resolve($r);$u=$this->customer();$cart=$this->carts->open($c,$r->cookies->get('mc_cart'));$this->saved->bindCustomer($cart['id'],$c->storeId,$u->id());try{$result=$this->saved->restore($id,$c,$u->id(),$cart['id']);$message=sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.savedcartcontroller.dodano_pozytsii_d'),$result['restored']);if($result['skipped']>0)$message.=\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.savedcartcontroller.nedostupnykh_propushcheno').$result['skipped'].'.';$this->addFlash($result['restored']>0?'success':'warning',$message);}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}$response=$this->redirectToRoute('storefront_cart');$this->cookie($response,$r,$cart);return$response;}
    #[Route('/account/saved-carts/{id}/rename',name:'customer_saved_cart_rename',methods:['POST'],priority:120)] public function rename(string $id,Request $r):Response{if(!$this->isCsrfTokenValid('saved_cart_rename_'.$id,(string)$r->request->get('_token')))throw$this->createAccessDeniedException();$c=$this->contexts->resolve($r);$u=$this->customer();try{$this->saved->rename($id,$c->storeId,$u->id(),(string)$r->request->get('name',''));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.savedcartcontroller.nazvu_koshyka_zmineno'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}return$this->redirectToRoute('customer_saved_carts');}
    #[Route('/account/saved-carts/{id}/delete',name:'customer_saved_cart_delete',methods:['POST'],priority:120)] public function delete(string $id,Request $r):Response{if(!$this->isCsrfTokenValid('saved_cart_delete_'.$id,(string)$r->request->get('_token')))throw$this->createAccessDeniedException();$c=$this->contexts->resolve($r);$u=$this->customer();$this->saved->delete($id,$c->storeId,$u->id());return$this->redirectToRoute('customer_saved_carts');}
    private function customer():CustomerUser{$u=$this->getUser();if(!$u instanceof CustomerUser)throw$this->createAccessDeniedException();return$u;}
    /** @param array{id:int,token:string,created:bool} $cart */ private function cookie(Response $res,Request $req,array $cart):void{if($cart['created'])$res->headers->setCookie(Cookie::create('mc_cart',$cart['token'])->withExpires(new \DateTimeImmutable('+7 days'))->withPath('/')->withSecure($req->isSecure())->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));}
}
