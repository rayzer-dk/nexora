<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Http;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Marketing\Application\NewsletterTokenService;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewsletterController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts,private readonly Connection $db,private readonly PublicIdFactory $ids,private readonly NotificationOutbox $outbox,private readonly NewsletterTokenService $unsubscribeTokens,private readonly \Commerce\Modules\Security\Spam\PublicFormProtection $protection,private readonly \Commerce\Modules\Notification\Application\EmailConfirmationSettings $confirmation){}

    #[Route('/newsletter/subscribe',name:'storefront_newsletter_subscribe',methods:['POST'])]
    public function subscribe(Request $request): Response
    {
        if(!$this->isCsrfTokenValid('newsletter_subscribe',(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        if(!$this->protection->allow($request,'newsletter','newsletter')){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.http.productfeedbackcontroller.formu_ne_pryiniato_onovit_storinku_ta_sprobuite_shch'));return $this->redirect((string)($request->headers->get('referer')?:'/'));}
        $context=$this->contexts->resolve($request);$email=mb_strtolower(trim((string)$request->request->get('email')));
        if(filter_var($email,FILTER_VALIDATE_EMAIL)===false){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.vkazhit_korektnyi_email'));return $this->redirect((string)($request->headers->get('referer')?:'/'));}
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$hash=hash('sha256',$token,true);$now=$this->now();
        $existing=$this->db->fetchAssociative('SELECT id,status FROM mc_marketing_subscriber WHERE store_id=? AND email_normalized=? LIMIT 1',[$context->storeId,$email]);
        if(is_array($existing)&&$existing['status']==='active'){$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.tsei_email_uzhe_pidpysanyi'));return $this->redirect((string)($request->headers->get('referer')?:'/'));}
        $confirmNeeded=$this->confirmation->required('newsletter');
        if(!$confirmNeeded){
            // The shop switched the confirmation off: the address counts at once.
            $values=['email'=>$email,'locale'=>$context->locale,'status'=>'active','confirm_token_hash'=>null,'consent_at'=>$now,'confirmed_at'=>$now,'unsubscribed_at'=>null,'updated_at'=>$now];
            if(is_array($existing))$this->db->update('mc_marketing_subscriber',$values,['id'=>(int)$existing['id']]);
            else $this->db->insert('mc_marketing_subscriber',$values+['public_id'=>$this->ids->binary(),'store_id'=>$context->storeId,'email_normalized'=>$email,'consent_source'=>'storefront','created_at'=>$now]);
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.tsei_email_uzhe_pidpysanyi'));return $this->redirect((string)($request->headers->get('referer')?:'/'));
        }
        if(is_array($existing))$this->db->update('mc_marketing_subscriber',['email'=>$email,'locale'=>$context->locale,'status'=>'pending','confirm_token_hash'=>$hash,'consent_at'=>$now,'confirmed_at'=>null,'unsubscribed_at'=>null,'updated_at'=>$now],['id'=>(int)$existing['id']]);
        else $this->db->insert('mc_marketing_subscriber',['public_id'=>$this->ids->binary(),'store_id'=>$context->storeId,'email'=>$email,'email_normalized'=>$email,'locale'=>$context->locale,'status'=>'pending','confirm_token_hash'=>$hash,'consent_source'=>'storefront','consent_at'=>$now,'confirmed_at'=>null,'unsubscribed_at'=>null,'created_at'=>$now,'updated_at'=>$now]);
        $url=$request->getSchemeAndHttpHost().'/newsletter/confirm/'.rawurlencode($token);
        $this->outbox->enqueue(NotificationChannel::Email,new NotificationMessage('newsletter.confirm',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.pidtverdit_pidpysku'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.pidtverdit_email_shchob_otrymuvaty_novyny_ta_propozy'),['action_url'=>$url,'action_label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.pidtverdyty_pidpysku')],'generic'),$email,null,'newsletter-confirm:'.$context->storeId.':'.hash('sha256',$email.':'.$token));
        $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.perevirte_email_i_pidtverdit_pidpysku'));return $this->redirect((string)($request->headers->get('referer')?:'/'));
    }

    #[Route('/newsletter/confirm/{token}',name:'storefront_newsletter_confirm',methods:['GET'],requirements:['token'=>'[A-Za-z0-9_-]{20,100}'])]
    public function confirm(string $token,Request $request): Response
    {
        $context=$this->contexts->resolve($request);$hash=hash('sha256',$token,true);$row=$this->db->fetchAssociative("SELECT id FROM mc_marketing_subscriber WHERE store_id=? AND status='pending' AND confirm_token_hash=? LIMIT 1",[$context->storeId,$hash]);
        if(!is_array($row)){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.posylannia_nediisne_abo_pidpysku_vzhe_pidtverdzheno'));return $this->redirectToRoute('storefront_home');}
        $now=$this->now();$this->db->update('mc_marketing_subscriber',['status'=>'active','confirm_token_hash'=>null,'confirmed_at'=>$now,'updated_at'=>$now],['id'=>(int)$row['id']]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.pidpysku_pidtverdzheno'));return $this->redirectToRoute('storefront_home');
    }


    #[Route('/newsletter/unsubscribe/{subscriber}/{signature}',name:'storefront_newsletter_unsubscribe',methods:['GET'],requirements:['subscriber'=>'[0-9a-fA-F-]{36}','signature'=>'[A-Za-z0-9_-]{20,100}'])]
    public function unsubscribe(string $subscriber,string $signature,Request $request):Response
    {
        if(!$this->unsubscribeTokens->verify($subscriber,$signature))throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_unsubscribe_signature'));
        $context=$this->contexts->resolve($request);try{$binary=\Symfony\Component\Uid\Uuid::fromString($subscriber)->toBinary();}catch(\Throwable){throw $this->createNotFoundException();}
        $row=$this->db->fetchAssociative('SELECT id FROM mc_marketing_subscriber WHERE public_id=? AND store_id=? LIMIT 1',[$binary,$context->storeId]);if(!is_array($row))throw $this->createNotFoundException();$now=$this->now();$this->db->update('mc_marketing_subscriber',['status'=>'unsubscribed','unsubscribed_at'=>$now,'updated_at'=>$now],['id'=>(int)$row['id']]);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.http.newslettercontroller.vy_vidpysalysia_vid_marketynhovykh_lystiv'));return $this->redirectToRoute('storefront_home');
    }

    private function now():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
}
