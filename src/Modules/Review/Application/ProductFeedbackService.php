<?php

declare(strict_types=1);
namespace Commerce\Modules\Review\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Automation\Application\AutomationEngine;
use Commerce\Modules\Media\Application\MediaImageService;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class ProductFeedbackService
{
    private const MAX_REVIEW_IMAGES = 4;

    public function __construct(
        private readonly Connection $db,
        private readonly PublicIdFactory $ids,
        private readonly MediaImageService $media,
        private readonly ?AutomationEngine $automation = null,
        private readonly ?ReviewSettings $settings = null,
        private readonly ?\Commerce\Modules\Notification\Application\TelegramAlertSettings $telegram = null,
    ) {}

    /** @param list<UploadedFile> $images */
    public function submitReview(StorefrontContext $ctx,?int $customerId,string $productPublicId,string $author,int $rating,string $title,string $body,array $images=[],string $guestEmail='',string $clientMark=''):void
    {
        if($rating<1||$rating>5)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.otsinka_maie_buty_vid_1_do_5'));
        $author=mb_substr(trim(strip_tags($author)),0,190);$title=mb_substr(trim(strip_tags($title)),0,255);$body=mb_substr(trim(strip_tags($body)),0,8000);
        if($author===''||mb_strlen($body)<3)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.zapovnit_imia_ta_tekst_vidhuku'));
        $product=$this->product($ctx->storeId,$productPublicId);
        [$who,$whoValue,$guestEmail,$clientHash]=$this->identity($customerId,$guestEmail,$clientMark);
        $exists=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_product_review WHERE store_id=? AND product_id=? AND $who AND status IN ('pending','published')",[$ctx->storeId,$product,$whoValue]);
        if($exists>0)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.vy_vzhe_zalyshyly_vidhuk_pro_tsei_tovar'));
        $recent=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_product_review WHERE store_id=? AND ".($customerId!==null?'customer_id=?':'client_hash=?')." AND created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 HOUR)",[$ctx->storeId,$customerId??$clientHash]);
        if($recent>=3)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.zabahato_vidhukiv_za_korotkyi_chas_sprobuite_piznish'));
        $verified=false;
        if($customerId!==null){        $verified=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id WHERE o.store_id=? AND o.customer_id=? AND oi.product_id=? AND o.status IN ('paid','processing','fulfilled','completed','shipped')",[$ctx->storeId,$customerId,$product])>0;}
        elseif($guestEmail!==''){$verified=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id WHERE o.store_id=? AND o.customer_email_normalized=? AND oi.product_id=? AND o.status IN ('paid','processing','fulfilled','completed','shipped')",[$ctx->storeId,mb_strtolower($guestEmail),$product])>0;}
        $validImages=[];foreach($images as $image){if($image instanceof UploadedFile&&$image->getError()!==UPLOAD_ERR_NO_FILE)$validImages[]=$image;}
        if(count($validImages)>self::MAX_REVIEW_IMAGES)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.do_vidhuku_mozhna_dodaty_ne_bilshe_4_foto'));
        $now=gmdate('Y-m-d H:i:s.u');
        $this->db->beginTransaction();
        try{
            $this->db->insert('mc_product_review',['public_id'=>$this->ids->binary(),'store_id'=>$ctx->storeId,'product_id'=>$product,'customer_id'=>$customerId,'guest_email'=>$guestEmail!==''?$guestEmail:null,'client_hash'=>$clientHash,'locale'=>$ctx->locale,'author_name'=>$author,'rating'=>$rating,'title'=>$title!==''?$title:null,'body'=>$body,'verified_purchase'=>$verified?1:0,'status'=>'pending','created_at'=>$now]);
            $reviewId=(int)$this->db->lastInsertId();
            foreach($validImages as $sort=>$image){$asset=$this->media->upload($image,$ctx->storeId,null,'reviews');$this->db->insert('mc_review_media',['review_id'=>$reviewId,'media_id'=>$asset->assetId,'sort_order'=>$sort]);}
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->isTransactionActive())$this->db->rollBack();throw $e;}
        $this->telegram?->alert('review',\Commerce\Core\I18n\CanonicalUiText::get('notify.tg.review'),$author.' · '.$rating.'/5'.($title!==''?' · '.$title:'')."\n".mb_substr($body,0,300),'review-tg:'.$reviewId);
        try{$this->automation?->fire($ctx->storeId,'review_created','review:'.$reviewId,['name'=>$author,'text'=>$author.' · '.$rating.'/5'.($title!==''?' · '.$title:''),'url'=>'/admin/customer-experience']);}catch(\Throwable){}
    }

    public function submitQuestion(StorefrontContext $ctx,?int $customerId,string $productPublicId,string $author,string $question,string $guestEmail='',string $clientMark=''):void
    {
        $author=mb_substr(trim(strip_tags($author)),0,190);$question=mb_substr(trim(strip_tags($question)),0,4000);if($author===''||mb_strlen($question)<5)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.zapovnit_imia_ta_pytannia'));
        $product=$this->product($ctx->storeId,$productPublicId);
        [$who,$whoValue,$guestEmail,$clientHash]=$this->identity($customerId,$guestEmail,$clientMark);$recent=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_product_question WHERE store_id=? AND ".($customerId!==null?'customer_id=?':'client_hash=?')." AND created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 HOUR)",[$ctx->storeId,$customerId??$clientHash]);
        if($recent>=5)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.zabahato_pytan_za_korotkyi_chas_sprobuite_piznishe'));
        $duplicate=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_product_question WHERE store_id=? AND product_id=? AND $who AND question=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY)",[$ctx->storeId,$product,$whoValue,$question]);
        if($duplicate>0)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.take_pytannia_vzhe_nadislano'));
        $now=gmdate('Y-m-d H:i:s.u');$this->db->insert('mc_product_question',['public_id'=>$this->ids->binary(),'store_id'=>$ctx->storeId,'product_id'=>$product,'customer_id'=>$customerId,'guest_email'=>$guestEmail!==''?$guestEmail:null,'client_hash'=>$clientHash,'locale'=>$ctx->locale,'author_name'=>$author,'question'=>$question,'status'=>'pending','created_at'=>$now]);
    }

    public function markHelpful(StorefrontContext $ctx,int $customerId,int $reviewId):int
    {
        $review=$this->db->fetchAssociative("SELECT id,customer_id FROM mc_product_review WHERE id=? AND store_id=? AND status='published'",[$reviewId,$ctx->storeId]);
        if(!is_array($review))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.vidhuk_ne_znaideno'));
        if((int)($review['customer_id']??0)===$customerId)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.review.application.productfeedbackservice.ne_mozhna_otsiniuvaty_vlasnyi_vidhuk'));
        try{$this->db->insert('mc_review_helpful_vote',['review_id'=>$reviewId,'customer_id'=>$customerId,'created_at'=>gmdate('Y-m-d H:i:s.u')]);$this->db->executeStatement('UPDATE mc_product_review SET helpful_count=helpful_count+1 WHERE id=?',[$reviewId]);}catch(\Doctrine\DBAL\Exception\UniqueConstraintViolationException){/* idempotent */}
        return (int)$this->db->fetchOne('SELECT helpful_count FROM mc_product_review WHERE id=?',[$reviewId]);
    }

    /** @return array{0:string,1:int|string,2:string,3:?string} SQL condition, its value, a clean guest e-mail (may be empty) and the hashed client mark */
    private function identity(?int $customerId,string $guestEmail,string $clientMark):array
    {
        if($customerId!==null)return ['customer_id=?',$customerId,'',null];
        $email=mb_strtolower(trim($guestEmail));
        if($email===''&&$this->settings?->emailRequired()===true||$email!==''&&(filter_var($email,FILTER_VALIDATE_EMAIL)===false||mb_strlen($email)>190))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('review.guest.email_required'));
        $hash=substr(hash('sha256',$clientMark),0,40);

        return $email!==''?['guest_email=?',$email,$email,$hash]:['client_hash=?',$hash,'',$hash];
    }

    private function product(int $storeId,string $publicId):int
    {
        try{$binary=Uuid::fromString($publicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.tovar_ne_znaideno'));}
        $id=$this->db->fetchOne("SELECT p.id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.public_id=? AND p.status='published' AND sp.status='active' LIMIT 1",[$storeId,$binary]);if($id===false)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.tovar_ne_znaideno'));return(int)$id;
    }
}
