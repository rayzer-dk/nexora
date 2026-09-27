<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Shipping\Application\NovaPostShipmentOperationService;
use Commerce\Modules\Shipping\Application\ShipmentOperationService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminShipmentController extends AbstractController
{
    public function __construct(private readonly ShipmentOperationService $shipments,private readonly NovaPostShipmentOperationService $novaPost,private readonly AdminContextResolver $contexts){}

    #[Route('/admin/shipments',name:'admin_shipments',methods:['GET'])]
    public function index(Request $request):Response{$ctx=$this->contexts->resolve($request);$status=trim((string)$request->query->get('status',''));return $this->render('@storefront/admin/shipping/index.html.twig',['shipments'=>$this->shipments->listForStore($ctx->storeId,$status),'status'=>$status]);}

    #[Route('/admin/shipments/bulk-status',name:'admin_shipment_bulk_status',methods:['POST'])]
    public function bulkStatus(Request $request):Response
    {
        if(!$this->isCsrfTokenValid('admin_shipment_bulk_status',(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);$ids=array_values(array_filter(array_map('strval',(array)$request->request->all('shipments'))));$status=(string)$request->request->get('status','');$done=$this->shipments->bulkStatus($ctx->storeId,$ids,$status,$this->actor());$this->addFlash($done>0?'success':'error',$done>0?(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.http.adminshipmentcontroller.onovleno_vidpravlen').$done):\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.http.adminshipmentcontroller.zhodne_vidpravlennia_ne_onovleno'));return $this->redirectToRoute('admin_shipments');
    }

    #[Route('/admin/orders/{order}/shipments',name:'admin_shipment_register',methods:['POST'],requirements:['order'=>'[0-9a-fA-F-]{36}'])]
    public function register(string $order,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('admin_shipment_'.$order,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        try{$this->shipments->register($ctx->storeId,$order,(string)$request->request->get('direction','outbound'),(string)$request->request->get('provider_code',''),(string)$request->request->get('service_type',''),(string)$request->request->get('tracking_number',''),(string)$request->request->get('external_id',''),(string)$request->request->get('carrier_label_url',''),(string)$request->request->get('note',''),$this->actor(),($v=trim((string)$request->request->get('return_public_id','')))!==''?$v:null);$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.http.adminshipmentcontroller.vidpravlennia_zareiestrovano'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        return $this->redirectToRoute('admin_order_view',['publicId'=>$order]);
    }

    #[Route('/admin/orders/{order}/shipments/nova-post',name:'admin_nova_post_create',methods:['POST'],requirements:['order'=>'[0-9a-fA-F-]{36}'])]
    public function createNovaPost(string $order,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('admin_nova_post_create_'.$order,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        try{$shipment=$this->novaPost->create($ctx->storeId,$order,(float)$request->request->get('weight_kg',0),(string)$request->request->get('description',''),$this->actor());$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.http.adminshipmentcontroller.ttn_novoi_poshty_stvoreno').(string)$shipment['tracking_number']);}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        return $this->redirectToRoute('admin_order_view',['publicId'=>$order]);
    }

    #[Route('/admin/shipments/{shipment}/nova-post/cancel',name:'admin_nova_post_cancel',methods:['POST'],requirements:['shipment'=>'[0-9a-fA-F-]{36}'])]
    public function cancelNovaPost(string $shipment,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('admin_nova_post_cancel_'.$shipment,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        try{$this->novaPost->cancel($ctx->storeId,$shipment,$this->actor());$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.http.adminshipmentcontroller.vidpravlennia_skasovano_u_novii_poshti_ta_lokalno'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        return $this->redirectToRoute('admin_shipment_view',['shipment'=>$shipment]);
    }

    #[Route('/admin/shipments/{shipment}/nova-post/tracking',name:'admin_nova_post_tracking',methods:['POST'],requirements:['shipment'=>'[0-9a-fA-F-]{36}'])]
    public function trackingNovaPost(string $shipment,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('admin_nova_post_tracking_'.$shipment,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        try{$data=$this->novaPost->tracking($ctx->storeId,$shipment);$status=trim((string)($data['Status']??$data['StatusCode']??''));$this->addFlash('success',$status!==''?(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.http.adminshipmentcontroller.nova_poshta').$status):\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.http.adminshipmentcontroller.tracking_otrymano_vidpovid_zberezhena_v_istorii_vidp'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        return $this->redirectToRoute('admin_shipment_view',['shipment'=>$shipment]);
    }

    #[Route('/admin/shipments/{shipment}/nova-post/label.pdf',name:'admin_nova_post_label',methods:['GET'],requirements:['shipment'=>'[0-9a-fA-F-]{36}'])]
    public function novaPostLabel(string $shipment,Request $request):Response
    {
        $ctx=$this->contexts->resolve($request);try{$pdf=$this->novaPost->labelPdf($ctx->storeId,$shipment);}catch(\DomainException $e){throw $this->createNotFoundException($e->getMessage());}
        return new Response($pdf,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="nova-poshta-label.pdf"','Cache-Control'=>'private, no-store']);
    }

    #[Route('/admin/shipments/{shipment}/status',name:'admin_shipment_status',methods:['POST'],requirements:['shipment'=>'[0-9a-fA-F-]{36}'])]
    public function status(string $shipment,Request $request):Response
    {
        if(!$this->isCsrfTokenValid('admin_shipment_status_'.$shipment,(string)$request->request->get('_token')))throw $this->createAccessDeniedException();$ctx=$this->contexts->resolve($request);
        try{$this->shipments->updateStatus($ctx->storeId,$shipment,(string)$request->request->get('status',''),$this->actor());$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.http.adminshipmentcontroller.status_vidpravlennia_onovleno'));}catch(\DomainException $e){$this->addFlash('error',$e->getMessage());}
        $order=trim((string)$request->request->get('order',''));return $order!==''?$this->redirectToRoute('admin_order_view',['publicId'=>$order]):$this->redirectToRoute('admin_shipments');
    }

    #[Route('/admin/shipments/{shipment}',name:'admin_shipment_view',methods:['GET'],requirements:['shipment'=>'[0-9a-fA-F-]{36}'])]
    public function view(string $shipment,Request $request):Response
    {
        $ctx=$this->contexts->resolve($request);try{$row=$this->shipments->get($ctx->storeId,$shipment);}catch(\DomainException){throw $this->createNotFoundException();}return $this->render('@storefront/admin/shipping/view.html.twig',['shipment'=>$row,'nova_post_label_configured'=>$this->novaPost->labelConfigured()]);
    }

    #[Route('/admin/shipments/{shipment}/label.pdf',name:'admin_shipment_label',methods:['GET'],requirements:['shipment'=>'[0-9a-fA-F-]{36}'])]
    public function label(string $shipment,Request $request):Response
    {
        $ctx=$this->contexts->resolve($request);$row=$this->shipments->get($ctx->storeId,$shipment);if(!class_exists(Dompdf::class))throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4aae35f9ef92'));$options=new Options();$options->set('isRemoteEnabled',false);$options->set('defaultFont','DejaVu Sans');$pdf=new Dompdf($options);$pdf->loadHtml($this->renderView('@storefront/admin/shipping/label.html.twig',['shipment'=>$row]),'UTF-8');$pdf->setPaper('A6','portrait');$pdf->render();return new Response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="shipment-'.$this->safe((string)$row['tracking_number']).'.pdf"']);
    }

    private function actor():string{$u=$this->getUser();return $u instanceof AdminUser?('admin:'.$u->id):'admin';}
    private function safe(string $v):string{return preg_replace('/[^A-Za-z0-9._-]+/','-',trim($v))?:'label';}
}
