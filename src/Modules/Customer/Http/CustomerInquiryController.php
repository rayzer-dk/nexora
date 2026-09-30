<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Http;

use Commerce\Modules\Customer\Application\CustomerInquiryService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInquiryController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts,private readonly CustomerInquiryService $service,private readonly \Commerce\Modules\Security\Spam\PublicFormProtection $protection) {}
    #[Route('/contact/send',name:'storefront_contact_send',methods:['POST'],priority:950)]
    public function contact(Request $request): Response { return $this->handle($request,null,'contact_form'); }
    #[Route('/product/{product}/inquiry',name:'storefront_product_inquiry',methods:['POST'],priority:100)]
    public function product(Request $request,string $product): Response { return $this->handle($request,$product,'product_inquiry_'.$product); }
    private function captchaForm(Request $request):string{return match((string)$request->request->get('inquiry_type','contact')){'callback'=>'callback','price_request'=>'price_request','quick_order'=>'quick_order',default=>'contact'};}
    private function handle(Request $request,?string $product,string $csrf): Response
    {
        if(!$this->isCsrfTokenValid($csrf,(string)$request->request->get('_token')))throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));$context=$this->contexts->resolve($request);
        if(!$this->protection->allow($request,'inquiry',$this->captchaForm($request))){$message=\Commerce\Core\I18n\CanonicalUiText::get('captcha_failed_or_spam');if($request->headers->get('X-Requested-With')==='XMLHttpRequest'||str_contains((string)$request->headers->get('Accept'),'application/json'))return new JsonResponse(['ok'=>false,'message'=>$message],429);$this->addFlash('error',$message);return $this->redirect($request->headers->get('referer')?:'/contact');}
        try{$this->service->create($context->storeId,$request->request->all(),$product);$ok=true;$message=\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customerinquirycontroller.diakuiemo_zvernennia_nadislano_menedzheru');}catch(\DomainException $e){$ok=false;$message=$e->getMessage();}
        if($request->headers->get('X-Requested-With')==='XMLHttpRequest'||str_contains((string)$request->headers->get('Accept'),'application/json'))return new JsonResponse(['ok'=>$ok,'message'=>$message],$ok?200:422);
        $this->addFlash($ok?'success':'error',$message);return $this->redirect($request->headers->get('referer')?:'/contact');
    }
}
