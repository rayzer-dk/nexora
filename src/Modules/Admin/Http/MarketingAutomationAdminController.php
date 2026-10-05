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
            try{$this->automations->upsert($context->storeId,$type,$request->request->getBoolean('enabled'),(int)$request->request->get('delay_hours',24),(string)$request->request->get('subject'),(string)$request->request->get('body'),(string)$request->request->get('coupon_code'),$request->request->getBoolean('is_html'),$request->request->getBoolean('include_leads'));$this->addFlash('success',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.marketingautomationadmincontroller.avtomatyzatsiiu_zberezheno'));}
            catch(\Throwable $e){$this->addFlash('error',$e instanceof \DomainException?$e->getMessage():\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.marketingautomationadmincontroller.ne_vdalosia_zberehty_avtomatyzatsiiu'));}
            return $this->redirectToRoute('admin_commerce_marketing_automation');
        }
        $rows=$this->db->fetchAllAssociative('SELECT * FROM mc_marketing_automation WHERE store_id=? ORDER BY FIELD(automation_type,\'abandoned_cart\',\'post_purchase\',\'win_back\'),id',[$context->storeId]);$map=[];foreach($rows as $r)$map[(string)$r['automation_type']]=$r;
        $stats=$this->db->fetchAllAssociative("SELECT a.automation_type,COUNT(d.id) deliveries,SUM(d.status='redeemed') recovered FROM mc_marketing_automation a LEFT JOIN mc_marketing_automation_delivery d ON d.automation_id=a.id WHERE a.store_id=? GROUP BY a.id,a.automation_type",[$context->storeId]);
        $attribution=$this->db->fetchAllAssociative("SELECT COALESCE(NULLIF(oa.last_source,''),'direct') source,COALESCE(NULLIF(oa.last_medium,''),'none') medium,COUNT(*) orders,SUM(o.total_minor) revenue_minor,o.currency FROM mc_sales_order o LEFT JOIN mc_order_attribution oa ON oa.order_id=o.id WHERE o.store_id=? AND o.status NOT IN ('cancelled','expired') AND o.created_at>=UTC_TIMESTAMP(6)-INTERVAL 90 DAY GROUP BY source,medium,o.currency ORDER BY revenue_minor DESC LIMIT 30",[$context->storeId]);
        [$abandoned,$abandonedStats]=$this->abandonedCarts($context->storeId,$context->locale);$recovery=$this->recoveryAnalytics($context->storeId);
        return $this->render('@storefront/admin/commerce/marketing_automation.html.twig',['abandoned'=>$abandoned,'abandoned_stats'=>$abandonedStats,'recovery'=>$recovery,'types'=>$this->automations->types(),'automations'=>$map,'stats'=>$stats,'attribution'=>$attribution,'segments'=>$this->segments->labels()]);
    }

    #[Route('/admin/commerce/marketing-automation/cart/{id}/remind',name:'admin_commerce_marketing_cart_remind',requirements:['id'=>'\d+'],methods:['POST'])]
    public function remind(int $id,Request $request):Response
    {
        $context=$this->contexts->resolve($request);
        if(!$this->isCsrfTokenValid('cart_remind_'.$id,(string)$request->request->get('_csrf_token')))throw $this->createAccessDeniedException();
        $result=$this->automations->remindCart($context->storeId,$id);
        $this->addFlash($result==='sent'?'success':'error',\Commerce\Core\I18n\CanonicalUiText::get('admin.carts.remind.'.$result));
        return $this->redirect($this->generateUrl('admin_commerce_marketing_automation').'#carts');
    }

    /**
     * What the reminders achieved: how many went out, how many shoppers ordered afterwards (and when, for how much),
     * and how many ordered at once without any reminder.
     *
     * @return array{stats:array<string,int|float|string>,rows:list<array<string,mixed>>}
     */
    private function recoveryAnalytics(int $storeId):array
    {
        $empty=['stats'=>['leads'=>0,'reminders'=>0,'carts_reminded'=>0,'ordered_after'=>0,'revenue_minor'=>0,'rate'=>0,'ordered_at_once'=>0,'currency'=>''],'rows'=>[]];
        try{
            $rows=$this->db->fetchAllAssociative("SELECT d.id,d.cart_id,d.recipient,d.status,d.created_at,d.sent_at,d.redeemed_at,(SELECT COALESCE(SUM(ci.quantity*ci.unit_price_minor),0) FROM mc_cart_item ci WHERE ci.cart_id=d.cart_id) cart_minor,(SELECT c.currency FROM mc_cart c WHERE c.id=d.cart_id) currency,(SELECT COUNT(*) FROM mc_marketing_automation_delivery d2 WHERE d2.cart_id=d.cart_id AND d2.id<=d.id) seq,(SELECT o.order_number FROM mc_sales_order o WHERE o.store_id=d.store_id AND o.customer_email_normalized=LOWER(d.recipient) AND o.created_at>=d.created_at AND o.status NOT IN ('cancelled','expired') ORDER BY o.created_at LIMIT 1) order_number,(SELECT o.total_minor FROM mc_sales_order o WHERE o.store_id=d.store_id AND o.customer_email_normalized=LOWER(d.recipient) AND o.created_at>=d.created_at AND o.status NOT IN ('cancelled','expired') ORDER BY o.created_at LIMIT 1) order_minor,(SELECT o.created_at FROM mc_sales_order o WHERE o.store_id=d.store_id AND o.customer_email_normalized=LOWER(d.recipient) AND o.created_at>=d.created_at AND o.status NOT IN ('cancelled','expired') ORDER BY o.created_at LIMIT 1) order_at FROM mc_marketing_automation_delivery d JOIN mc_marketing_automation a ON a.id=d.automation_id AND a.automation_type='abandoned_cart' WHERE d.store_id=? ORDER BY d.id DESC LIMIT 100",[$storeId]);
            $leads=(int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_checkout_lead WHERE store_id=?',[$storeId]);
            $atOnce=(int)$this->db->fetchOne("SELECT COUNT(*) FROM mc_checkout_lead l JOIN mc_cart c ON c.id=l.cart_id WHERE l.store_id=? AND c.status='converted' AND NOT EXISTS(SELECT 1 FROM mc_marketing_automation_delivery d WHERE d.cart_id=c.id)",[$storeId]);
        }catch(\Throwable){return $empty;}
        $stats=$empty['stats'];$stats['leads']=$leads;$stats['ordered_at_once']=$atOnce;$carts=[];$orderedRecipients=[];
        foreach($rows as &$r){
            $stats['reminders']++;$carts[(int)$r['cart_id']]=true;
            $r['outcome']=$r['order_number']!==null?(((int)$r['seq'])===1?'ordered_after_first':'ordered_after_repeat'):'not_ordered';
            if($r['order_number']!==null){$key=strtolower((string)$r['recipient']).'|'.$r['order_number'];if(!isset($orderedRecipients[$key])){$orderedRecipients[$key]=true;$stats['ordered_after']++;$stats['revenue_minor']+=(int)$r['order_minor'];}}
            if($stats['currency']==='')$stats['currency']=(string)($r['currency']??'');
        }
        unset($r);
        $stats['carts_reminded']=count($carts);
        $stats['rate']=$stats['carts_reminded']>0?round($stats['ordered_after']*100/$stats['carts_reminded'],1):0;
        return ['stats'=>$stats,'rows'=>$rows];
    }

    /**
     * Carts that were left for at least an hour: who, what, how much, when, and whether the reminder email went out.
     *
     * @return array{0:list<array<string,mixed>>,1:array<string,int>}
     */
    private function abandonedCarts(int $storeId,string $locale):array
    {
        try{
            $carts=$this->db->fetchAllAssociative("SELECT c.id,c.currency,c.updated_at,COALESCE(NULLIF(cu.display_name,''),l.customer_name) display_name,COALESCE(cu.email,l.email) email,COALESCE(cu.phone_e164,l.phone) phone_e164,SUM(ci.quantity*ci.unit_price_minor) total_minor,COUNT(ci.id) line_count,MAX(l.id) IS NOT NULL from_checkout FROM mc_cart c JOIN mc_cart_item ci ON ci.cart_id=c.id LEFT JOIN mc_customer cu ON cu.id=c.customer_id LEFT JOIN mc_checkout_lead l ON l.cart_id=c.id WHERE c.store_id=? AND c.status='active' AND c.updated_at<(UTC_TIMESTAMP(6)-INTERVAL 1 HOUR) AND c.updated_at>(UTC_TIMESTAMP(6)-INTERVAL 60 DAY) GROUP BY c.id,c.currency,c.updated_at,cu.display_name,l.customer_name,cu.email,l.email,cu.phone_e164,l.phone ORDER BY (COALESCE(cu.email,l.email) IS NULL AND COALESCE(cu.phone_e164,l.phone) IS NULL),c.updated_at DESC LIMIT 100",[$storeId]);
        }catch(\Throwable){return [[],['total'=>0,'with_contact'=>0,'emailed'=>0,'recovered'=>0,'value_minor'=>0]];}
        $ids=array_map(static fn(array $c):int=>(int)$c['id'],$carts);
        $items=[];$mail=[];$sentCount=[];
        if($ids!==[]){
            $in=implode(',',array_fill(0,count($ids),'?'));
            foreach($this->db->fetchAllAssociative("SELECT ci.cart_id,ci.quantity,ci.unit_price_minor,v.product_id,COALESCE(pt.name,v.sku) name,(SELECT ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=v.product_id ORDER BY pm.sort_order,pm.media_asset_id LIMIT 1) image_key FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id LEFT JOIN mc_product_translation pt ON pt.product_id=v.product_id AND pt.locale=? WHERE ci.cart_id IN ($in) ORDER BY ci.id",[$locale,...$ids]) as $r){
                $items[(int)$r['cart_id']][]=['name'=>(string)$r['name'],'quantity'=>(float)$r['quantity'],'price_minor'=>(int)$r['unit_price_minor'],'product_id'=>(int)$r['product_id'],'image'=>($r['image_key']??'')!==''?'/media/'.ltrim((string)$r['image_key'],'/'):''];
            }
            try{
                foreach($this->db->fetchAllAssociative("SELECT d.cart_id,d.status,d.sent_at,d.redeemed_at,d.created_at FROM mc_marketing_automation_delivery d WHERE d.cart_id IN ($in) ORDER BY d.id",$ids) as $r){$mail[(int)$r['cart_id']]=$r;$sentCount[(int)$r['cart_id']]=($sentCount[(int)$r['cart_id']]??0)+1;}
            }catch(\Throwable){}
        }
        $stats=['total'=>0,'with_contact'=>0,'emailed'=>0,'recovered'=>0,'value_minor'=>0];
        foreach($carts as &$c){
            $id=(int)$c['id'];$c['items']=$items[$id]??[];$d=$mail[$id]??null;
            $c['mail_state']=$d===null?(($c['email']??'')===''?(($c['phone_e164']??'')===''?'no_contact':'phone_only'):'not_sent'):($d['redeemed_at']!==null?'recovered':($d['sent_at']!==null?'sent':'queued'));
            $c['mail_at']=$d['sent_at']??null;$c['mail_count']=$sentCount[$id]??0;
            $stats['total']++;$stats['value_minor']+=(int)$c['total_minor'];
            if(($c['email']??'')!==''||($c['phone_e164']??'')!=='')$stats['with_contact']++;
            if(in_array($c['mail_state'],['sent','recovered'],true))$stats['emailed']++;
            if($c['mail_state']==='recovered')$stats['recovered']++;
        }
        unset($c);
        return [$carts,$stats];
    }
}
