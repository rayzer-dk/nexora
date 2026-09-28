<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EmailPreviewAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts) {}

    #[Route('/admin/commerce/email-preview', name:'admin_email_preview', methods:['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        $template=(string)$request->query->get('template','order_created');
        if(!in_array($template,['order_created','order_status','generic','campaign'],true))$template='order_created';
        return $this->render('@storefront/admin/commerce/email_preview.html.twig',['template'=>$template]);
    }

    #[Route('/admin/commerce/email-preview/render/{template}', name:'admin_email_preview_render', methods:['GET'], requirements:['template'=>'order_created|order_status|generic|campaign'])]
    public function renderPreview(Request $request,string $template): Response
    {
        $this->contexts->resolve($request);
        $context=['notification_subject'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.demonstratsiine_povidomlennia'),'notification_text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.tak_vyhliadatyme_povidomlennia_kliientu_tekst_adaptu'),'store_name'=>'Nexora Commerce','locale'=>'uk-UA','footer_text'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.tse_demonstratsiinyi_preview_realni_lysty_vykorystov'),'action_url'=>'#','action_label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.vidkryty'),'unsubscribe_url'=>'#','customer_name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.oleksandr'),'order_number'=>'MC-2026-00125','items'=>[['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.smartfon_demo_pro_256gb'),'sku'=>'DEMO-PHONE-01','quantity'=>'1','unit_code'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.sht'),'line_total_minor'=>2899900],['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.zakhysne_sklo'),'sku'=>'DEMO-GLASS-01','quantity'=>'1','unit_code'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.sht'),'line_total_minor'=>49900]],'currency'=>'UAH','discount_minor'=>30000,'total_minor'=>2919900,'fulfillment_label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.nova_poshta_viddilennia_12'),'status'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.komplektuietsia'),'payment_status'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.oplacheno'),'fulfillment_status'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.emailpreviewadmincontroller.hotuietsia_do_vidpravlennia'),'tracking_number'=>'20450000000000'];
        $response=$this->render('@storefront/email/'.$template.'.html.twig',$context);$response->headers->set('X-Frame-Options','SAMEORIGIN');$response->headers->set('Cache-Control','no-store');return $response;
    }
}
