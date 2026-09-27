<?php

declare(strict_types=1);
namespace Commerce\Modules\Review\Http;

use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Review\Application\ProductFeedbackService;
use Commerce\Modules\Security\Spam\PublicFormSpamGuard;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductFeedbackController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly ProductFeedbackService $feedback,
        private readonly PublicFormSpamGuard $spamGuard,
    ) {}

    #[Route('/product-feedback/{product}/review',name:'product_review_submit',methods:['POST'],priority:200)]
    public function review(string $product,Request $request):Response
    {
        $user=$this->customer();if(!$this->isCsrfTokenValid('review_'.$product,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        $guard=$this->spamGuard->check((string)$request->request->get('_website',''),(int)$request->request->get('_rendered_at',0),time(),strlen((string)$request->request->get('title','').(string)$request->request->get('body','')));
        if(!$guard->allowed){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.formu_ne_pryiniato_onovit_storinku_ta_sprobuite_shch'));return $this->redirect($this->back($request).'#reviews');}
        $images=$request->files->all('images');if(!is_array($images))$images=[];
        try{$this->feedback->submitReview($ctx,$user->id(),$product,$user->getDisplayName(),(int)$request->request->get('rating',0),(string)$request->request->get('title',''),(string)$request->request->get('body',''),array_values($images));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.vidhuk_nadislano_na_moderatsiiu'));}catch(\DomainException|\InvalidArgumentException $e){$this->addFlash('error',$e->getMessage());}catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.ne_vdalosia_nadislaty_vidhuk'));}
        return $this->redirect($this->back($request).'#reviews');
    }

    #[Route('/product-feedback/{product}/question',name:'product_question_submit',methods:['POST'],priority:200)]
    public function question(string $product,Request $request):Response
    {
        $user=$this->customer();if(!$this->isCsrfTokenValid('question_'.$product,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        $guard=$this->spamGuard->check((string)$request->request->get('_website',''),(int)$request->request->get('_rendered_at',0),time(),strlen((string)$request->request->get('question','')));
        if(!$guard->allowed){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.formu_ne_pryiniato_onovit_storinku_ta_sprobuite_shch'));return $this->redirect($this->back($request).'#questions');}
        try{$this->feedback->submitQuestion($ctx,$user->id(),$product,$user->getDisplayName(),(string)$request->request->get('question',''));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.pytannia_nadislano_na_moderatsiiu'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}catch(\Throwable){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.ne_vdalosia_nadislaty_pytannia'));}
        return $this->redirect($this->back($request).'#questions');
    }

    #[Route('/product-feedback/reviews/{id}/helpful',name:'product_review_helpful',methods:['POST'],requirements:['id'=>'\\d+'],priority:200)]
    public function helpful(int $id,Request $request):Response
    {
        $user=$this->customer();if(!$this->isCsrfTokenValid('review_helpful_'.$id,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        try{$this->feedback->markHelpful($ctx,$user->id(),$id);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.diakuiemo_za_otsinku_vidhuku'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        return $this->redirect($this->back($request).'#reviews');
    }

    private function customer():CustomerUser{$u=$this->getUser();if(!$u instanceof CustomerUser)throw $this->createAccessDeniedException();return $u;}
    private function back(Request $r):string{$uri=(string)$r->headers->get('referer','/catalog');$p=parse_url($uri,PHP_URL_PATH);return is_string($p)&&str_starts_with($p,'/')?$p:'/catalog';}
}
