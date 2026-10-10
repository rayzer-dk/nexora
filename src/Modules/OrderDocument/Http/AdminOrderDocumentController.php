<?php

declare(strict_types=1);

namespace Commerce\Modules\OrderDocument\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\OrderDocument\Application\OrderDocumentService;
use Commerce\Core\I18n\CanonicalUiText;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminOrderDocumentController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly OrderDocumentService $documents, private readonly \Commerce\Modules\OrderDocument\Application\OrderDocumentPdf $pdfs, private readonly \Commerce\Modules\Order\Application\OrderNotificationService $notifications, private readonly \Doctrine\DBAL\Connection $db) {}

    #[Route('/admin/orders/{order}/documents/issue',name:'admin_order_document_issue',methods:['POST'],requirements:['order'=>'[0-9a-fA-F-]{36}'])]
    public function issue(string $order,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('admin_order_document_'.$order,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        $ctx=$this->contexts->resolve($request); $type=(string)$request->request->get('type','');
        try{
            $doc=match($type){
                'invoice'=>$this->documents->issueInvoice($ctx->storeId,$order,$this->actor()),
                'packing_slip'=>$this->documents->issuePackingSlip($ctx->storeId,$order,$this->actor()),
                'credit_note'=>$this->documents->issueCreditNote($ctx->storeId,$order,(int)$request->request->get('refund_id',0),$this->actor()),
                default=>throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.nevidomyi_typ_dokumenta')),
            };
            $this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.dokument').$doc['document_number'].\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.hotovyi'));
        }catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        return $this->redirectToRoute('admin_order_view',['publicId'=>$order]);
    }

    #[Route('/admin/orders/documents/{document}',name:'admin_order_document_view',methods:['GET'],requirements:['document'=>'[0-9a-fA-F-]{36}'])]
    public function view(string $document,Request $request):Response
    {
        $ctx=$this->contexts->resolve($request); $doc=$this->documents->documentForStore($ctx->storeId,$document);
        return $this->render('@storefront/order_document/document.html.twig',['document'=>$doc,'pdf_mode'=>false]);
    }

    #[Route('/admin/orders/documents/{document}.pdf',name:'admin_order_document_pdf',methods:['GET'],requirements:['document'=>'[0-9a-fA-F-]{36}'])]
    public function pdf(string $document,Request $request):Response
    {
        $ctx=$this->contexts->resolve($request); $doc=$this->documents->documentForStore($ctx->storeId,$document);
        return $this->pdfResponse($doc);
    }

    private function pdfResponse(array $doc):Response
    {
        return new Response($this->pdfs->render($doc),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$this->pdfs->filename($doc).'"','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }

    /** One click: the invoice (or receipt, packing slip) goes to the customer's e-mail as a PDF attachment. */
    #[Route('/admin/orders/documents/{document}/email',name:'admin_order_document_email',methods:['POST'],requirements:['document'=>'[0-9a-fA-F-]{36}'])]
    public function email(string $document,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('admin_order_document_email_'.$document,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();
        $ctx=$this->contexts->resolve($request);$orderPublicId=trim((string)$request->request->get('order',''));
        try{
            $doc=$this->documents->documentForStore($ctx->storeId,$document);
            $number=$this->db->fetchOne('SELECT order_number FROM mc_sales_order WHERE public_id=? AND store_id=?',[\Symfony\Component\Uid\Uuid::fromString($orderPublicId)->toBinary(),$ctx->storeId]);
            if($number===false||(string)$number!==$doc['order_number'])throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.application.orderdocumentservice.dokument_ne_znaideno'));
            $dir=(string)$this->getParameter('kernel.project_dir').'/var/order-mail/'.bin2hex(random_bytes(8));
            if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new \RuntimeException('mail dir');
            $name=$this->pdfs->filename($doc);file_put_contents($dir.'/'.$name,$this->pdfs->render($doc));
            $label=match($doc['document_type']){'invoice'=>CanonicalUiText::get('admin.order_document.invoice'),'packing_slip'=>CanonicalUiText::get('admin.order_document.packing_slip'),default=>CanonicalUiText::get('admin.order_document.credit_note')};
            $subject=str_replace(['%type%','%number%'],[$label,(string)$doc['order_number']],CanonicalUiText::get('admin.order_document.mail_subject'));
            $text=str_replace(['%type%','%number%'],[$label,(string)$doc['order_number']],CanonicalUiText::get('admin.order_document.mail_text'));
            if(!$this->notifications->enqueueMessage($orderPublicId,$text,$subject,[['path'=>$dir.'/'.$name,'name'=>$name]]))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.u_zamovlennia_nemaie_korektnoho_email_pokuptsia'));
            $this->addFlash('success',CanonicalUiText::get('admin.order_message.queued'));
        }catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}catch(\Throwable){$this->addFlash('error',CanonicalUiText::get('admin.order_document.mail_failed'));}
        return $this->redirectToRoute('admin_order_view',['publicId'=>$orderPublicId]);
    }

    private function actor():string{$u=$this->getUser();return $u instanceof AdminUser?$u->displayName:\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.administrator');}
}
