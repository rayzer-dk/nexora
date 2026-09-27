<?php

declare(strict_types=1);
namespace Commerce\Modules\Marketing\Http;
use Commerce\Modules\Cart\Application\CartMutationService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
final class MarketingRecoveryController extends AbstractController
{
    public function __construct(private readonly Connection $db,private readonly StorefrontContextResolver $contexts,private readonly CartMutationService $carts){}

    #[Route('/marketing/cart/recover/{delivery}/{token}',name:'storefront_marketing_cart_recover',methods:['GET'],requirements:['delivery'=>'[0-9a-fA-F-]{36}','token'=>'[A-Za-z0-9_-]{32,128}'])]
    public function confirm(string $delivery,string $token,Request $request):Response
    {
        $row=$this->validDelivery($delivery,$token,$request);$count=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_cart_item WHERE cart_id=?',[(int)$row['cart_id']]);
        return $this->render('@storefront/marketing/cart_recovery.html.twig',['page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.marketingrecoverycontroller.vidnovlennia_koshyka'),'delivery'=>$delivery,'token'=>$token,'item_count'=>$count,'seo_head'=>['robots'=>'noindex,nofollow']]);
    }

    #[Route('/marketing/cart/recover/{delivery}/{token}',name:'storefront_marketing_cart_recover_apply',methods:['POST'],requirements:['delivery'=>'[0-9a-fA-F-]{36}','token'=>'[A-Za-z0-9_-]{32,128}'])]
    public function recover(string $delivery,string $token,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('cart_recovery_'.$delivery,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        $row=$this->validDelivery($delivery,$token,$request);$context=$this->contexts->resolve($request);$current=$this->carts->open($context,$request->cookies->get('mc_cart'));$items=$this->db->fetchAllAssociative('SELECT v.public_id,ci.quantity FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id WHERE ci.cart_id=? ORDER BY ci.id',[(int)$row['cart_id']]);$restored=0;$skipped=0;
        foreach($items as $item){try{$this->carts->add($context,$current['id'],Uuid::fromBinary((string)$item['public_id'])->toRfc4122(),(string)$item['quantity']);$restored++;}catch(\Throwable){$skipped++;}}
        $this->db->update('mc_marketing_automation_delivery',['redeemed_at'=>gmdate('Y-m-d H:i:s.u'),'status'=>'redeemed'],['id'=>(int)$row['id']]);
        $this->addFlash($restored>0?'success':'warning',sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.marketingrecoverycontroller.koshyk_vidnovleno_d_pozytsii_propushcheno_d'),$restored,$skipped));$response=$this->redirectToRoute('storefront_cart');if($current['created'])$response->headers->setCookie(Cookie::create('mc_cart',$current['token'])->withExpires(new \DateTimeImmutable('+7 days'))->withPath('/')->withSecure($request->isSecure())->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));return $response;
    }

    /** @return array<string,mixed> */ private function validDelivery(string $delivery,string $token,Request $request):array
    {
        try{$binary=Uuid::fromString($delivery)->toBinary();}catch(\Throwable){throw $this->createNotFoundException();}
        $row=$this->db->fetchAssociative("SELECT d.id,d.store_id,d.cart_id,d.recovery_token_hash,d.recovery_expires_at,d.redeemed_at FROM mc_marketing_automation_delivery d JOIN mc_marketing_automation a ON a.id=d.automation_id AND a.automation_type='abandoned_cart' WHERE d.public_id=? LIMIT 1",[$binary]);
        if(!is_array($row)||$row['cart_id']===null||$row['recovery_token_hash']===null||$row['redeemed_at']!==null||strtotime((string)$row['recovery_expires_at'])<time()||!hash_equals((string)$row['recovery_token_hash'],hash('sha256',$token,true)))throw $this->createNotFoundException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.marketingrecoverycontroller.posylannia_vidnovlennia_nediisne_abo_prostrochene'));
        $context=$this->contexts->resolve($request);if($context->storeId!==(int)$row['store_id'])throw $this->createAccessDeniedException();return $row;
    }
}
