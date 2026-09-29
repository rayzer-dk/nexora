<?php

declare(strict_types=1);
namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Return\Application\ReturnRequestService;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class CustomerExperienceAdminController extends AbstractController
{
    public function __construct(private readonly Connection $db,private readonly AdminContextResolver $contexts,private readonly ReturnRequestService $returns,private readonly NotificationOutbox $notifications){}

    #[Route('/admin/customer-experience',name:'admin_customer_experience',methods:['GET'])]
    public function index(Request $request):Response
    {
        $ctx=$this->contexts->resolve($request);
        $returns=$this->db->fetchAllAssociative("SELECT rr.public_id,rr.status,rr.reason_code,rr.resolution,rr.created_at,o.order_number,o.currency,o.total_minor,c.display_name customer_name,COUNT(ri.id) item_count FROM mc_return_request rr JOIN mc_sales_order o ON o.id=rr.order_id LEFT JOIN mc_customer c ON c.id=rr.customer_id LEFT JOIN mc_return_item ri ON ri.return_id=rr.id WHERE rr.store_id=? GROUP BY rr.id ORDER BY rr.id DESC LIMIT 100",[$ctx->storeId]);
        foreach($returns as &$r){$r['public_id']=Uuid::fromBinary((string)$r['public_id'])->toRfc4122();$r['item_count']=(int)$r['item_count'];}unset($r);
        $reviews=$this->db->fetchAllAssociative("SELECT r.id,r.author_name,r.rating,r.title,r.body,r.verified_purchase,r.helpful_count,r.merchant_reply,r.status,r.created_at,pt.name product_name FROM mc_product_review r JOIN mc_product_translation pt ON pt.product_id=r.product_id AND pt.store_id=r.store_id AND pt.locale=? WHERE r.store_id=? ORDER BY FIELD(r.status,'pending','published','rejected','spam'),r.id DESC LIMIT 100",[$ctx->locale,$ctx->storeId]);
        foreach($reviews as &$review){$media=$this->db->fetchAllAssociative('SELECT ma.storage_key FROM mc_review_media rm JOIN mc_media_asset ma ON ma.id=rm.media_id WHERE rm.review_id=? ORDER BY rm.sort_order,rm.media_id LIMIT 4',[(int)$review['id']]);$review['images']=array_map(static fn(array $m):string=>'/media/'.ltrim((string)$m['storage_key'],'/'),$media);}unset($review);
        try{$questions=$this->db->fetchAllAssociative("SELECT q.id,q.author_name,q.question,q.answer,q.status,q.created_at,pt.name product_name FROM mc_product_question q JOIN mc_product_translation pt ON pt.product_id=q.product_id AND pt.store_id=q.store_id AND pt.locale=? WHERE q.store_id=? ORDER BY FIELD(q.status,'pending','published','rejected'),q.id DESC LIMIT 100",[$ctx->locale,$ctx->storeId]);}catch(\Throwable){$questions=[];}
        try{$withdrawals=$this->db->fetchAllAssociative('SELECT order_reference,customer_name,email,scope_note,status,received_at,acknowledged_at,order_id FROM mc_withdrawal_notice WHERE store_id=? ORDER BY id DESC LIMIT 100',[$ctx->storeId]);}catch(\Throwable){$withdrawals=[];}
        return $this->render('@storefront/admin/customer_experience/index.html.twig',['withdrawals'=>$withdrawals,'returns'=>$returns,'reviews'=>$reviews,'questions'=>$questions,'return_statuses'=>ReturnRequestService::STATUSES]);
    }


    #[Route('/admin/customer-experience/returns/{publicId}',name:'admin_return_view',methods:['GET'],priority:20)]
    public function returnView(string $publicId, Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        try{$return=$this->returns->adminDetail($ctx->storeId,$publicId);}catch(\DomainException){throw $this->createNotFoundException();}
        return $this->render('@storefront/admin/customer_experience/return_view.html.twig',[
            'return'=>$return,
            'resolutions'=>ReturnRequestService::RESOLUTIONS,
        ]);
    }

    #[Route('/admin/customer-experience/returns/{publicId}',name:'admin_return_status',methods:['POST'])]
    public function returnStatus(string $publicId,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('return_status_'.$publicId,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);$u=$this->getUser();$adminId=$u instanceof AdminUser?$u->id:null;
        try{$this->returns->updateStatus($ctx->storeId,$publicId,(string)$request->request->get('status','requested'),$adminId,(string)$request->request->get('note',''),(string)$request->request->get('resolution',''),(string)$request->request->get('tracking_number',''));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.status_povernennia_onovleno'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        $back=(string)$request->request->get('_back','');
        return $back==='detail'?$this->redirectToRoute('admin_return_view',['publicId'=>$publicId]):$this->redirectToRoute('admin_customer_experience');
    }

    #[Route('/admin/customer-experience/reviews/{id}',name:'admin_review_moderate',methods:['POST'],requirements:['id'=>'\\d+'])]
    public function review(int $id,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('review_moderate_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);$status=(string)$request->request->get('status','pending');if(!in_array($status,['pending','published','rejected','spam'],true))throw $this->createNotFoundException();
        $reply=mb_substr(trim(strip_tags((string)$request->request->get('merchant_reply',''))),0,8000);$now=gmdate('Y-m-d H:i:s.u');
        $row=$this->db->fetchAssociative("SELECT r.customer_id,r.merchant_reply,c.email,pt.name product_name FROM mc_product_review r LEFT JOIN mc_customer c ON c.id=r.customer_id LEFT JOIN mc_product_translation pt ON pt.product_id=r.product_id AND pt.store_id=r.store_id AND pt.locale=? WHERE r.id=? AND r.store_id=? LIMIT 1",[$ctx->locale,$id,$ctx->storeId]);if(!is_array($row))throw $this->createNotFoundException();
        $data=['status'=>$status,'published_at'=>$status==='published'?$now:null,'merchant_reply'=>$reply!==''?$reply:null,'merchant_replied_at'=>$reply!==''?$now:null];$this->db->update('mc_product_review',$data,['id'=>$id,'store_id'=>$ctx->storeId]);
        if($status==='published'&&$reply!==''&&$reply!==(string)($row['merchant_reply']??'')&&filter_var((string)($row['email']??''),FILTER_VALIDATE_EMAIL)!==false){try{$this->notifications->enqueue(NotificationChannel::Email,new NotificationMessage('review.reply',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.mahazyn_vidpoviv_na_vash_vidhuk'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.do_vashoho_vidhuku_pro').(string)($row['product_name']??\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.tovar')).\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.dodano_vidpovid_mahazynu').$reply,[],'generic'),(string)$row['email'],null,'review-reply:'.$ctx->storeId.':'.$id.':'.hash('sha256',$reply));}catch(\Throwable){}}
        return $this->redirectToRoute('admin_customer_experience');
    }

    #[Route('/admin/customer-experience/questions/{id}',name:'admin_question_moderate',methods:['POST'],requirements:['id'=>'\\d+'])]
    public function question(int $id,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('question_moderate_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);$status=(string)$request->request->get('status','pending');if(!in_array($status,['pending','published','rejected'],true))throw $this->createNotFoundException();$answer=mb_substr(trim(strip_tags((string)$request->request->get('answer',''))),0,8000);if($status==='published'&&$answer===''){$this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.dlia_publikatsii_pytannia_dodaite_vidpovid'));return $this->redirectToRoute('admin_customer_experience');}$now=gmdate('Y-m-d H:i:s.u');
        $row=$this->db->fetchAssociative("SELECT q.answer,c.email,pt.name product_name FROM mc_product_question q LEFT JOIN mc_customer c ON c.id=q.customer_id LEFT JOIN mc_product_translation pt ON pt.product_id=q.product_id AND pt.store_id=q.store_id AND pt.locale=? WHERE q.id=? AND q.store_id=? LIMIT 1",[$ctx->locale,$id,$ctx->storeId]);if(!is_array($row))throw $this->createNotFoundException();
        $this->db->update('mc_product_question',['status'=>$status,'answer'=>$answer!==''?$answer:null,'answered_at'=>$answer!==''?$now:null,'published_at'=>$status==='published'?$now:null],['id'=>$id,'store_id'=>$ctx->storeId]);
        if($status==='published'&&$answer!==''&&$answer!==(string)($row['answer']??'')&&filter_var((string)($row['email']??''),FILTER_VALIDATE_EMAIL)!==false){try{$this->notifications->enqueue(NotificationChannel::Email,new NotificationMessage('product.question.answer',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.vidpovid_na_vashe_pytannia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.mahazyn_vidpoviv_na_vashe_pytannia_pro').(string)($row['product_name']??\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.customerexperienceadmincontroller.tovar')).'»: '.$answer,[],'generic'),(string)$row['email'],null,'question-answer:'.$ctx->storeId.':'.$id.':'.hash('sha256',$answer));}catch(\Throwable){}}
        return $this->redirectToRoute('admin_customer_experience');
    }
}
