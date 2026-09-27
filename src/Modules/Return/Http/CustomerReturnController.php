<?php

declare(strict_types=1);
namespace Commerce\Modules\Return\Http;

use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Return\Application\ReturnRequestService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerReturnController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts,private readonly ReturnRequestService $returns){}

    #[Route('/account/orders/{order}/return',name:'customer_return_create',methods:['POST'],priority:125)]
    public function create(string $order,Request $request):Response
    {
        $user=$this->getUser();if(!$user instanceof CustomerUser)throw $this->createAccessDeniedException();
        if(!$this->isCsrfTokenValid('return_create_'.$order,(string)$request->request->get('_token')))throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        $ctx=$this->contexts->resolve($request);$raw=$request->request->all('quantity');$quantities=[];foreach($raw as $id=>$qty){if(ctype_digit((string)$id))$quantities[(int)$id]=trim((string)$qty);}
        try{$this->returns->create($ctx->storeId,$user->id(),$order,$quantities,(string)$request->request->get('reason','other'),(string)$request->request->get('note',''));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.http.customerreturncontroller.zapyt_na_povernennia_stvoreno_status_mozhna_vidstezh'));}
        catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.return.http.customerreturncontroller.ne_vdalosia_stvoryty_povernennia_dani_zamovlennia_ne'));}
        return $this->redirectToRoute('customer_account_order',['order'=>$order]);
    }
}
