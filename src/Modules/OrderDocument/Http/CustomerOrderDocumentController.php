<?php

declare(strict_types=1);

namespace Commerce\Modules\OrderDocument\Http;

use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\OrderDocument\Application\OrderDocumentService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerOrderDocumentController extends AbstractController
{
    public function __construct(private readonly StorefrontContextResolver $contexts,private readonly OrderDocumentService $documents){}

    #[Route('/account/documents/{document}',name:'customer_order_document_view',methods:['GET'],priority:125,requirements:['document'=>'[0-9a-fA-F-]{36}'])]
    public function view(string $document,Request $request):Response
    {
        $doc=$this->document($document,$request); return $this->render('@storefront/order_document/document.html.twig',['document'=>$doc,'pdf_mode'=>false]);
    }

    #[Route('/account/documents/{document}.pdf',name:'customer_order_document_pdf',methods:['GET'],priority:125,requirements:['document'=>'[0-9a-fA-F-]{36}'])]
    public function pdf(string $document,Request $request):Response
    {
        $doc=$this->document($document,$request); if(!class_exists(Dompdf::class))throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f3cb1230282d'));
        $options=new Options();$options->set('isRemoteEnabled',false);$options->set('defaultFont','DejaVu Sans');$pdf=new Dompdf($options);$pdf->loadHtml($this->renderView('@storefront/order_document/document.html.twig',['document'=>$doc,'pdf_mode'=>true]),'UTF-8');$pdf->setPaper('A4');$pdf->render();
        $filename=preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$doc['document_number']).'.pdf';
        return new Response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$filename.'"','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }

    private function document(string $id,Request $request):array
    {
        $user=$this->getUser();if(!$user instanceof CustomerUser)throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        try{return $this->documents->documentForCustomer($user->id(),$ctx->storeId,$id);}catch(\DomainException){throw $this->createNotFoundException();}
    }
}
