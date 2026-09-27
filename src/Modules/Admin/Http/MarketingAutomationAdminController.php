<?php

declare(strict_types=1);
namespace Commerce\Modules\Admin\Http;
use Commerce\Modules\Marketing\Application\MarketingAutomationService;
use Commerce\Modules\Marketing\Application\MarketingSegmentService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class MarketingAutomationAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts,private readonly Connection $db,private readonly MarketingAutomationService $automations,private readonly MarketingSegmentService $segments){}
    #[Route('/admin/commerce/marketing-automation',name:'admin_commerce_marketing_automation',methods:['GET','POST'])]
    public function index(Request $request):Response
    {
        $context=$this->contexts->resolve($request);
        if($request->isMethod('POST')){
            $type=(string)$request->request->get('automation_type');
            if(!$this->isCsrfTokenValid('marketing_automation_'.$type,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
            try{$this->automations->upsert($context->storeId,$type,$request->request->getBoolean('enabled'),(int)$request->request->get('delay_hours',24),(string)$request->request->get('subject'),(string)$request->request->get('body'),(string)$request->request->get('coupon_code'));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.marketingautomationadmincontroller.avtomatyzatsiiu_zberezheno'));}
            catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.marketingautomationadmincontroller.ne_vdalosia_zberehty_avtomatyzatsiiu'));}
            return $this->redirectToRoute('admin_commerce_marketing_automation');
        }
        $rows=$this->db->fetchAllAssociative('SELECT * FROM mc_marketing_automation WHERE store_id=? ORDER BY FIELD(automation_type,\'abandoned_cart\',\'post_purchase\',\'win_back\'),id',[$context->storeId]);$map=[];foreach($rows as $r)$map[(string)$r['automation_type']]=$r;
        $stats=$this->db->fetchAllAssociative("SELECT a.automation_type,COUNT(d.id) deliveries,SUM(d.status='redeemed') recovered FROM mc_marketing_automation a LEFT JOIN mc_marketing_automation_delivery d ON d.automation_id=a.id WHERE a.store_id=? GROUP BY a.id,a.automation_type",[$context->storeId]);
        $attribution=$this->db->fetchAllAssociative("SELECT COALESCE(NULLIF(oa.last_source,''),'direct') source,COALESCE(NULLIF(oa.last_medium,''),'none') medium,COUNT(*) orders,SUM(o.total_minor) revenue_minor,o.currency FROM mc_sales_order o LEFT JOIN mc_order_attribution oa ON oa.order_id=o.id WHERE o.store_id=? AND o.status NOT IN ('cancelled','expired') AND o.created_at>=UTC_TIMESTAMP(6)-INTERVAL 90 DAY GROUP BY source,medium,o.currency ORDER BY revenue_minor DESC LIMIT 30",[$context->storeId]);
        return $this->render('@storefront/admin/commerce/marketing_automation.html.twig',['types'=>$this->automations->types(),'automations'=>$map,'stats'=>$stats,'attribution'=>$attribution,'segments'=>$this->segments->labels()]);
    }
}
