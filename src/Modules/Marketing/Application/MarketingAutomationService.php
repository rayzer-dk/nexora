<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;

final readonly class MarketingAutomationService
{
    public function __construct(private Connection $db,private NotificationOutbox $outbox,private PublicIdFactory $ids,private string $publicBaseUrl,#[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'html_sanitizer.sanitizer.commerce.rich_text')] private ?\Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface $sanitizer=null){}
    /** @return array<string,string> */ public function types():array{return ['abandoned_cart'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingautomationservice.pokynutyi_koshyk'),'post_purchase'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingautomationservice.pislia_pokupky'),'win_back'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingautomationservice.povernennia_kliienta')];}
    public function upsert(int $storeId,string $type,bool $enabled,int $delayHours,string $subject,string $body,?string $coupon,bool $html=false,bool $includeLeads=false):void
    {
        if(!isset($this->types()[$type]))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingautomationservice.nevidomyi_typ_avtomatyzatsii'));$delayHours=max(1,min(8760,$delayHours));$subject=trim($subject);$body=trim($body);if($html&&$this->sanitizer!==null)$body=trim($this->sanitizer->sanitize($body));$coupon=trim((string)$coupon)?:null;
        if($subject===''||mb_strlen($subject)>255||$body===''||mb_strlen($body)>20000)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingautomationservice.zapovnit_temu_i_tekst_avtomatyzatsii'));
        if($coupon!==null && preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$coupon)!==1)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingautomationservice.nekorektnyi_promokod'));
        $existing=$this->db->fetchOne('SELECT id FROM mc_marketing_automation WHERE store_id=? AND automation_type=?',[$storeId,$type]);$now=gmdate('Y-m-d H:i:s.u');
        $data=['enabled'=>$enabled?1:0,'delay_hours'=>$delayHours,'subject'=>$subject,'body_text'=>$body,'is_html'=>$html?1:0,'include_leads'=>$includeLeads?1:0,'coupon_code'=>$coupon,'updated_at'=>$now];
        if($existing!==false)$this->db->update('mc_marketing_automation',$data,['id'=>(int)$existing]);else $this->db->insert('mc_marketing_automation',array_merge(['store_id'=>$storeId,'automation_type'=>$type,'created_at'=>$now],$data));
    }
    /** @return array{queued:int,scanned:int} */ public function scan(int $limit=200):array
    {
        $limit=max(1,min(1000,$limit));$autos=$this->db->fetchAllAssociative("SELECT * FROM mc_marketing_automation WHERE enabled=1 ORDER BY id ASC");$queued=0;$scanned=0;
        foreach($autos as $a){$remaining=max(0,$limit-$scanned);if($remaining===0)break;$rows=$this->candidates($a,$remaining);foreach($rows as $r){$scanned++;if($this->enqueue($a,$r))$queued++;}}
        return ['queued'=>$queued,'scanned'=>$scanned];
    }
    /** @return list<array<string,mixed>> */ private function candidates(array $a,int $limit):array
    {
        $store=(int)$a['store_id'];$hours=(int)$a['delay_hours'];$type=(string)$a['automation_type'];
        if($type==='abandoned_cart'){$leads=(int)($a['include_leads']??0)===1?1:0;return $this->db->fetchAllAssociative("SELECT c.id cart_id,c.customer_id,COALESCE(cu.email,l.email) email,COALESCE(cu.display_name,l.customer_name) display_name,NULL order_id,l.locale FROM mc_cart c LEFT JOIN mc_customer cu ON cu.id=c.customer_id LEFT JOIN mc_checkout_lead l ON l.cart_id=c.id LEFT JOIN mc_marketing_subscriber s ON s.store_id=c.store_id AND s.email_normalized=LOWER(COALESCE(cu.email,l.email)) AND s.status='active' WHERE c.store_id=? AND c.status='active' AND COALESCE(cu.email,l.email) IS NOT NULL AND (s.id IS NOT NULL OR ($leads=1 AND l.email IS NOT NULL)) AND c.updated_at<=UTC_TIMESTAMP(6)-INTERVAL $hours HOUR AND c.updated_at>=UTC_TIMESTAMP(6)-INTERVAL 7 DAY AND EXISTS(SELECT 1 FROM mc_cart_item ci WHERE ci.cart_id=c.id) ORDER BY c.updated_at ASC LIMIT $limit",[$store]);}
        if($type==='post_purchase')return $this->db->fetchAllAssociative("SELECT NULL cart_id,o.customer_id,o.customer_email email,o.customer_name display_name,o.id order_id FROM mc_sales_order o JOIN mc_marketing_subscriber s ON s.store_id=o.store_id AND s.email_normalized=o.customer_email_normalized AND s.status='active' WHERE o.store_id=? AND o.status NOT IN ('cancelled','expired') AND o.created_at<=UTC_TIMESTAMP(6)-INTERVAL $hours HOUR AND o.created_at>=UTC_TIMESTAMP(6)-INTERVAL ".($hours+72)." HOUR ORDER BY o.id ASC LIMIT $limit",[$store]);
        return $this->db->fetchAllAssociative("SELECT NULL cart_id,c.id customer_id,c.email,c.display_name,NULL order_id FROM mc_customer c JOIN mc_marketing_subscriber s ON s.email_normalized=c.email_normalized AND s.store_id=? AND s.status='active' WHERE c.status='active' AND EXISTS(SELECT 1 FROM mc_sales_order o WHERE o.store_id=? AND o.customer_id=c.id AND o.status NOT IN ('cancelled','expired')) AND NOT EXISTS(SELECT 1 FROM mc_sales_order o2 WHERE o2.store_id=? AND o2.customer_id=c.id AND o2.status NOT IN ('cancelled','expired') AND o2.created_at>=UTC_TIMESTAMP(6)-INTERVAL $hours HOUR) ORDER BY c.id ASC LIMIT $limit",[$store,$store,$store]);
    }
    private function enqueue(array $a,array $r,string $manualSuffix=''):bool
    {
        $subject=(string)$a['subject'];$body=(string)$a['body_text'];$email=mb_strtolower(trim((string)$r['email']));if(filter_var($email,FILTER_VALIDATE_EMAIL)===false)return false;
        $entity=(string)$a['automation_type']==='abandoned_cart'?'cart:'.(int)$r['cart_id']:((string)$a['automation_type']==='post_purchase'?'order:'.(int)$r['order_id']:'customer:'.(int)$r['customer_id']);
        $cycle=$manualSuffix!==''?$manualSuffix:((string)$a['automation_type']==='win_back'?gmdate('Y-m'):'once');$dedupe=hash('sha256','automation:'.$a['id'].':'.$entity.':'.$cycle);
        if((int)$this->db->fetchOne('SELECT COUNT(*) FROM mc_marketing_automation_delivery WHERE dedupe_key=?',[$dedupe])>0)return false;
        $public=$this->ids->generate();$recoveryToken=null;$recoveryHash=null;$recoveryExpires=null;
        if((string)$a['automation_type']==='abandoned_cart'){$recoveryToken=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$recoveryHash=hash('sha256',$recoveryToken,true);$recoveryExpires=gmdate('Y-m-d H:i:s.u',time()+7*86400);}
        $cartUrl=$recoveryToken!==null?rtrim($this->publicBaseUrl,'/').'/marketing/cart/recover/'.$public->toRfc4122().'/'.$recoveryToken:rtrim($this->publicBaseUrl,'/').'/cart';
        $vars=['customer_name'=>(string)($r['display_name']??''),'coupon_code'=>(string)($a['coupon_code']??''),'cart_url'=>$cartUrl,'account_url'=>rtrim($this->publicBaseUrl,'/').'/account'];$items=[];$total=0;$currency='';if((string)$a['automation_type']==='abandoned_cart'&&$r['cart_id']!==null){[$items,$total,$currency]=$this->cartItems((int)$r['cart_id'],(string)($r['locale']??''));}
        foreach($vars as $k=>$v){$subject=str_replace('{{'.$k.'}}',$v,$subject);$body=str_replace('{{'.$k.'}}',$v,$body);} 
        $this->outbox->enqueue(NotificationChannel::Email,new NotificationMessage('marketing.automation.'.$a['automation_type'],$subject,$body,['coupon_code'=>$a['coupon_code']??null,'customer_name'=>$vars['customer_name'],'items'=>$items,'total_minor'=>$total,'currency'=>$currency,'custom_html'=>(int)($a['is_html']??0)===1?$body:'','action_url'=>(string)$a['automation_type']==='abandoned_cart'?$vars['cart_url']:$vars['account_url'],'action_label'=>(string)$a['automation_type']==='abandoned_cart'?\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingautomationservice.vidnovyty_koshyk'):\Commerce\Core\I18n\CanonicalUiText::get('php.modules.marketing.application.marketingautomationservice.vidkryty_kabinet')],'generic'),$email,null,'marketing-auto:'.$dedupe);
        $this->db->insert('mc_marketing_automation_delivery',['public_id'=>$public->toBinary(),'automation_id'=>(int)$a['id'],'store_id'=>(int)$a['store_id'],'customer_id'=>$r['customer_id']!==null?(int)$r['customer_id']:null,'cart_id'=>$r['cart_id']!==null?(int)$r['cart_id']:null,'order_id'=>$r['order_id']!==null?(int)$r['order_id']:null,'recipient'=>$email,'status'=>'queued','dedupe_key'=>$dedupe,'recovery_token_hash'=>$recoveryHash,'recovery_expires_at'=>$recoveryExpires,'redeemed_at'=>null,'created_at'=>gmdate('Y-m-d H:i:s.u'),'sent_at'=>null]);return true;
    }
    /** @return array{0:list<array<string,mixed>>,1:int,2:string} lines with a product photo, the cart total and its currency */
    public function cartItems(int $cartId,string $locale=''):array
    {
        $rows=$this->db->fetchAllAssociative("SELECT ci.quantity,ci.unit_price_minor,c.currency,COALESCE(pt.name,v.sku) name,(SELECT ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=v.product_id ORDER BY pm.sort_order,pm.media_asset_id LIMIT 1) image_key FROM mc_cart_item ci JOIN mc_cart c ON c.id=ci.cart_id JOIN mc_product_variant v ON v.id=ci.variant_id LEFT JOIN mc_product_translation pt ON pt.product_id=v.product_id AND pt.locale=? WHERE ci.cart_id=? ORDER BY ci.id",[$locale,$cartId]);
        $items=[];$total=0;$currency='';$base=rtrim($this->publicBaseUrl,'/');
        foreach($rows as $r){$q=(float)$r['quantity'];$line=(int)round($q*(int)$r['unit_price_minor']);$total+=$line;$currency=(string)$r['currency'];$items[]=['name'=>(string)$r['name'],'quantity'=>$q,'unit_price_minor'=>(int)$r['unit_price_minor'],'line_total_minor'=>$line,'image_url'=>($r['image_key']??'')!==''?$base.'/media/'.ltrim((string)$r['image_key'],'/'):''];}
        return [$items,$total,$currency];
    }

    /** Makes sure the lifecycle template exists (switched off) so a manual reminder always has a subject and text. */
    public function ensure(int $storeId,string $type):array
    {
        $row=$this->db->fetchAssociative('SELECT * FROM mc_marketing_automation WHERE store_id=? AND automation_type=?',[$storeId,$type]);
        if(is_array($row))return $row;
        $now=gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_marketing_automation',['store_id'=>$storeId,'automation_type'=>$type,'enabled'=>0,'delay_hours'=>24,'subject'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.commerce.marketing_automation.vy_zalyshyly_tovary_u_koshyku'),'body_text'=>\Commerce\Core\I18n\CanonicalUiText::get('admin.commerce.marketing_automation.vashi_tovary_vse_shche_chekaiut_vidnovit_koshyk_'),'is_html'=>0,'include_leads'=>0,'coupon_code'=>null,'created_at'=>$now,'updated_at'=>$now]);
        return (array)$this->db->fetchAssociative('SELECT * FROM mc_marketing_automation WHERE store_id=? AND automation_type=?',[$storeId,$type]);
    }

    /** Sends the abandoned-cart reminder for one cart right now, whatever the automatic schedule says. @return 'sent'|'no_email'|'missing' */
    public function remindCart(int $storeId,int $cartId):string
    {
        $r=$this->db->fetchAssociative("SELECT c.id cart_id,c.customer_id,COALESCE(cu.email,l.email) email,COALESCE(cu.display_name,l.customer_name) display_name,NULL order_id,l.locale FROM mc_cart c LEFT JOIN mc_customer cu ON cu.id=c.customer_id LEFT JOIN mc_checkout_lead l ON l.cart_id=c.id WHERE c.id=? AND c.store_id=? AND c.status='active' AND EXISTS(SELECT 1 FROM mc_cart_item ci WHERE ci.cart_id=c.id)",[$cartId,$storeId]);
        if(!is_array($r))return 'missing';
        if(filter_var(trim((string)($r['email']??'')),FILTER_VALIDATE_EMAIL)===false)return 'no_email';
        $this->enqueue($this->ensure($storeId,'abandoned_cart'),$r,'manual:'.bin2hex(random_bytes(6)));
        return 'sent';
    }
}
