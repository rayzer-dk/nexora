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
            try{$this->automations->upsert($context->storeId,$type,$request->request->getBoolean('enabled'),(int)$request->request->get('delay_hours',24),(string)$request->request->get('subject'),(string)$request->request->get('body'),(string)$request->request->get('coupon_code'),$request->request->getBoolean('is_html'));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.marketingautomationadmincontroller.avtomatyzatsiiu_zberezheno'));}
            catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.marketingautomationadmincontroller.ne_vdalosia_zberehty_avtomatyzatsiiu'));}
            return $this->redirectToRoute('admin_commerce_marketing_automation');
        }
        $rows=$this->db->fetchAllAssociative('SELECT * FROM mc_marketing_automation WHERE store_id=? ORDER BY FIELD(automation_type,\'abandoned_cart\',\'post_purchase\',\'win_back\'),id',[$context->storeId]);$map=[];foreach($rows as $r)$map[(string)$r['automation_type']]=$r;
        $stats=$this->db->fetchAllAssociative("SELECT a.automation_type,COUNT(d.id) deliveries,SUM(d.status='redeemed') recovered FROM mc_marketing_automation a LEFT JOIN mc_marketing_automation_delivery d ON d.automation_id=a.id WHERE a.store_id=? GROUP BY a.id,a.automation_type",[$context->storeId]);
        $attribution=$this->db->fetchAllAssociative("SELECT COALESCE(NULLIF(oa.last_source,''),'direct') source,COALESCE(NULLIF(oa.last_medium,''),'none') medium,COUNT(*) orders,SUM(o.total_minor) revenue_minor,o.currency FROM mc_sales_order o LEFT JOIN mc_order_attribution oa ON oa.order_id=o.id WHERE o.store_id=? AND o.status NOT IN ('cancelled','expired') AND o.created_at>=UTC_TIMESTAMP(6)-INTERVAL 90 DAY GROUP BY source,medium,o.currency ORDER BY revenue_minor DESC LIMIT 30",[$context->storeId]);
        [$abandoned,$abandonedStats]=$this->abandonedCarts($context->storeId,$context->locale);
        return $this->render('@storefront/admin/commerce/marketing_automation.html.twig',['abandoned'=>$abandoned,'abandoned_stats'=>$abandonedStats,'types'=>$this->automations->types(),'automations'=>$map,'stats'=>$stats,'attribution'=>$attribution,'segments'=>$this->segments->labels()]);
    }

    /**
     * Carts that were left for at least an hour: who, what, how much, when, and whether the reminder email went out.
     *
     * @return array{0:list<array<string,mixed>>,1:array<string,int>}
     */
    private function abandonedCarts(int $storeId,string $locale):array
    {
        try{
            $carts=$this->db->fetchAllAssociative("SELECT c.id,c.currency,c.updated_at,cu.display_name,cu.email,cu.phone_e164,SUM(ci.quantity*ci.unit_price_minor) total_minor,COUNT(ci.id) line_count FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id LEFT JOIN mc_customer cu ON cu.id=c.customer_id WHERE c.store_id=? AND c.status='active' AND c.updated_at<(UTC_TIMESTAMP(6)-INTERVAL 1 HOUR) AND c.updated_at>(UTC_TIMESTAMP(6)-INTERVAL 60 DAY) GROUP BY c.id,c.currency,c.updated_at,cu.display_name,cu.email,cu.phone_e164 ORDER BY c.updated_at DESC LIMIT 100",[$storeId]);
        }catch(\Throwable){return [[],['total'=>0,'with_contact'=>0,'emailed'=>0,'recovered'=>0,'value_minor'=>0]];}
        $ids=array_map(static fn(array $c):int=>(int)$c['id'],$carts);
        $items=[];$mail=[];
        if($ids!==[]){
            $in=implode(',',array_fill(0,count($ids),'?'));
            foreach($this->db->fetchAllAssociative("SELECT ci.cart_id,ci.quantity,COALESCE(pt.name,v.sku) name FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id LEFT JOIN mc_product_translation pt ON pt.product_id=v.product_id AND pt.locale=? WHERE ci.cart_id IN ($in) ORDER BY ci.id",[$locale,...$ids]) as $r){
                $items[(int)$r['cart_id']][]=['name'=>(string)$r['name'],'quantity'=>(float)$r['quantity']];
            }
            try{
                foreach($this->db->fetchAllAssociative("SELECT d.cart_id,d.status,d.sent_at,d.redeemed_at,d.created_at FROM mc_marketing_automation_delivery d WHERE d.cart_id IN ($in) ORDER BY d.id",$ids) as $r){$mail[(int)$r['cart_id']]=$r;}
            }catch(\Throwable){}
        }
        $stats=['total'=>0,'with_contact'=>0,'emailed'=>0,'recovered'=>0,'value_minor'=>0];
        foreach($carts as &$c){
            $id=(int)$c['id'];$c['items']=$items[$id]??[];$d=$mail[$id]??null;
            $c['mail_state']=$d===null?(($c['email']??'')===''?'no_contact':'not_sent'):($d['redeemed_at']!==null?'recovered':($d['sent_at']!==null?'sent':'queued'));
            $c['mail_at']=$d['sent_at']??null;
            $stats['total']++;$stats['value_minor']+=(int)$c['total_minor'];
            if(($c['email']??'')!=='')$stats['with_contact']++;
            if(in_array($c['mail_state'],['sent','recovered'],true))$stats['emailed']++;
            if($c['mail_state']==='recovered')$stats['recovered']++;
        }
        unset($c);
        return [$carts,$stats];
    }
}
