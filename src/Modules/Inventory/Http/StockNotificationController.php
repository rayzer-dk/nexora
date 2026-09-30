<?php

declare(strict_types=1);

namespace Commerce\Modules\Inventory\Http;

use Commerce\Modules\Inventory\Application\StockNotificationService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StockNotificationController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts,private readonly StockNotificationService $notifications,private readonly \Commerce\Modules\Security\Spam\PublicFormProtection $protection) {}

    #[Route('/product/{product}/stock-notify',name:'storefront_stock_notify',methods:['POST'],priority:100)]
    public function request(Request $request,string $product): Response
    {
        if(!$this->isCsrfTokenValid('stock_notify_'.$product,(string)$request->request->get('_token'))) throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        $context=$this->contexts->resolve($request);
        if(!$this->protection->allow($request,'stock')){$message=\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.formu_ne_pryiniato_onovit_storinku_ta_sprobuite_shch');if($request->headers->get('X-Requested-With')==='XMLHttpRequest'||str_contains((string)$request->headers->get('Accept'),'application/json'))return new JsonResponse(['ok'=>false,'message'=>$message],429);$this->addFlash('error',$message);return $this->redirect($request->headers->get('referer') ?: '/catalog');}
        try{$this->notifications->request($context->storeId,$product,(string)$request->request->get('variant_id'),(string)$request->request->get('email'),$context->locale);$ok=true;$message=\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.http.stocknotificationcontroller.perevirte_email_i_pidtverdit_spovishchennia');}
        catch(\DomainException $e){$ok=false;$message=$e->getMessage();}
        if($request->headers->get('X-Requested-With')==='XMLHttpRequest'||str_contains((string)$request->headers->get('Accept'),'application/json')) return new JsonResponse(['ok'=>$ok,'message'=>$message],$ok?200:422);
        $this->addFlash($ok?'success':'error',$message); return $this->redirect($request->headers->get('referer') ?: '/catalog');
    }

    #[Route('/stock-alert/confirm/{requestId}/{token}',name:'storefront_stock_notify_confirm',methods:['GET'],priority:100)]
    public function confirm(string $requestId,string $token): Response
    {
        $ok=$this->notifications->confirm($requestId,$token);
        return $this->render('@storefront/content/message.html.twig',['page_title'=>$ok?\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.http.stocknotificationcontroller.spovishchennia_pidtverdzheno'):\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.http.stocknotificationcontroller.posylannia_nediisne'),'title'=>$ok?\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.http.stocknotificationcontroller.hotovo'):\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.http.stocknotificationcontroller.ne_vdalosia_pidtverdyty'),'message'=>$ok?\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.http.stocknotificationcontroller.my_nadishlemo_lyst_koly_tovar_znovu_bude_dostupnyi'):\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.http.stocknotificationcontroller.posylannia_nediisne_abo_vzhe_neaktualne'),'seo_head'=>['robots'=>'noindex,nofollow']]);
    }
}
