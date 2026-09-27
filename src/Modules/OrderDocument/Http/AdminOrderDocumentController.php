<?php

declare(strict_types=1);

namespace Commerce\Modules\OrderDocument\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\OrderDocument\Application\OrderDocumentService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminOrderDocumentController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly OrderDocumentService $documents) {}

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
        if(!class_exists(Dompdf::class))throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.19ce105d6ea9'));
        $options=new Options(); $options->set('isRemoteEnabled',false); $options->set('defaultFont','DejaVu Sans');
        $pdf=new Dompdf($options); $pdf->loadHtml($this->renderView('@storefront/order_document/document.html.twig',['document'=>$doc,'pdf_mode'=>true]),'UTF-8'); $pdf->setPaper('A4','portrait'); $pdf->render();
        $filename=preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$doc['document_number']).'.pdf';
        return new Response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$filename.'"','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }

    private function actor():string{$u=$this->getUser();return $u instanceof AdminUser?$u->displayName:\Commerce\Core\I18n\CanonicalUiText::get('php.modules.orderdocument.http.adminorderdocumentcontroller.administrator');}
}
