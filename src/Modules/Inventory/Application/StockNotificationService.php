<?php

declare(strict_types=1);

namespace Commerce\Modules\Inventory\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class StockNotificationService
{
    public function __construct(private Connection $db, private PublicIdFactory $ids, private NotificationOutbox $notifications, private string $publicBaseUrl, private ?\Commerce\Modules\Automation\Application\AutomationEngine $automation = null) {}

    public function request(int $storeId, string $productPublicId, string $variantPublicId, string $email, string $locale): void
    {
        $email=mb_strtolower(trim($email));
        if(filter_var($email,FILTER_VALIDATE_EMAIL)===false) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.vkazhit_korektnyi_email'));
        if(!Uuid::isValid($productPublicId)||!Uuid::isValid($variantPublicId)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.nekorektnyi_tovar'));
        $row=$this->db->fetchAssociative('SELECT p.id product_id,v.id variant_id,pt.name FROM mc_product p JOIN mc_product_variant v ON v.product_id=p.id JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=? WHERE p.public_id=? AND v.public_id=? LIMIT 1',[$storeId,$storeId,$locale,Uuid::fromString($productPublicId)->toBinary(),Uuid::fromString($variantPublicId)->toBinary()]);
        if(!is_array($row)) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.tovar_ne_znaideno'));
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); $tokenHash=hash('sha256',$token,true); $now=gmdate('Y-m-d H:i:s.u');
        $existing=$this->db->fetchAssociative('SELECT id,public_id FROM mc_stock_notification_request WHERE store_id=? AND variant_id=? AND email_normalized=? LIMIT 1',[$storeId,(int)$row['variant_id'],$email]);
        $public=$existing?Uuid::fromBinary((string)$existing['public_id']):$this->ids->generate();
        if($existing){$this->db->update('mc_stock_notification_request',['product_id'=>(int)$row['product_id'],'email'=>$email,'locale'=>$locale,'status'=>'pending','confirm_token_hash'=>$tokenHash,'confirmed_at'=>null,'notified_at'=>null,'updated_at'=>$now],['id'=>(int)$existing['id']]);}
        else{$this->db->insert('mc_stock_notification_request',['public_id'=>$public->toBinary(),'store_id'=>$storeId,'product_id'=>(int)$row['product_id'],'variant_id'=>(int)$row['variant_id'],'email'=>$email,'email_normalized'=>$email,'locale'=>$locale,'status'=>'pending','confirm_token_hash'=>$tokenHash,'confirmed_at'=>null,'notified_at'=>null,'created_at'=>$now,'updated_at'=>$now]);}
        $url=rtrim($this->publicBaseUrl,'/').'/stock-alert/confirm/'.$public->toRfc4122().'/'.rawurlencode($token);
        $this->notifications->enqueue(NotificationChannel::Email,new NotificationMessage('stock_alert_confirm',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.pidtverdit_spovishchennia'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.pidtverdit_shcho_khochete_otrymaty_lyst_koly_tovar').(string)$row['name'].\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.znovu_bude_dostupnyi'),['action_url'=>$url,'action_label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.pidtverdyty')],'generic'),$email,null,'stock-confirm:'.$public->toRfc4122().':'.hash('sha256',$token));
    }

    public function confirm(string $requestPublicId,string $token): bool
    {
        if(!Uuid::isValid($requestPublicId)||$token==='')return false;
        $row=$this->db->fetchAssociative('SELECT id,confirm_token_hash,status FROM mc_stock_notification_request WHERE public_id=? LIMIT 1',[Uuid::fromString($requestPublicId)->toBinary()]);
        if(!is_array($row)||!in_array((string)$row['status'],['pending','active'],true))return false;
        if(!hash_equals((string)$row['confirm_token_hash'],hash('sha256',$token,true)))return false;
        if((string)$row['status']==='active')return true;
        $this->db->update('mc_stock_notification_request',['status'=>'active','confirmed_at'=>gmdate('Y-m-d H:i:s.u'),'updated_at'=>gmdate('Y-m-d H:i:s.u')],['id'=>(int)$row['id']]);
        // The shop owner learns that a shopper is really waiting (rules: e-mail, Telegram, push; the header bell counts it too).
        try{$info=$this->db->fetchAssociative('SELECT r.store_id,r.email,pt.name FROM mc_stock_notification_request r LEFT JOIN mc_product_translation pt ON pt.product_id=r.product_id AND pt.store_id=r.store_id AND pt.locale=r.locale WHERE r.id=?',[(int)$row['id']]);if(is_array($info))$this->automation?->fire((int)$info['store_id'],'stock_waiting','stock:'.(int)$row['id'],['name'=>(string)$info['email'],'text'=>(string)$info['email'].' · '.(string)($info['name']??''),'url'=>'/admin/commerce/stock-requests']);}catch(\Throwable){}
        return true;
    }

    public function notifyAvailableProduct(string $productPublicId): void
    {
        if(!Uuid::isValid($productPublicId))return;$productId=(int)$this->db->fetchOne('SELECT id FROM mc_product WHERE public_id=? LIMIT 1',[Uuid::fromString($productPublicId)->toBinary()]);if($productId<1)return;
        $rows=$this->db->fetchAllAssociative("SELECT r.id,r.public_id,r.email,r.locale,pt.name,sr.path FROM mc_stock_notification_request r JOIN mc_product_variant v ON v.id=r.variant_id JOIN mc_variant_inventory_item vii ON vii.variant_id=v.id JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_product_translation pt ON pt.product_id=r.product_id AND pt.store_id=r.store_id AND pt.locale=r.locale LEFT JOIN mc_seo_route sr ON sr.entity_type='product' AND sr.entity_public_id=(SELECT public_id FROM mc_product WHERE id=r.product_id) AND sr.store_id=r.store_id AND sr.locale=r.locale AND sr.indexable=1 WHERE r.product_id=? AND r.status='active' GROUP BY r.id,r.public_id,r.email,r.locale,pt.name,sr.path HAVING SUM(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock)>0",[$productId]);
        foreach($rows as $row){$path=!empty($row['path'])?'/'.ltrim((string)$row['path'],'/'):'/product/'.$productPublicId;$url=rtrim($this->publicBaseUrl,'/').$path;$public=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();$this->notifications->enqueue(NotificationChannel::Email,new NotificationMessage('stock_available',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.tovar_znovu_v_naiavnosti'),\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.tovar').(string)$row['name'].\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.znovu_dostupnyi_dlia_zamovlennia'),['action_url'=>$url,'action_label'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.inventory.application.stocknotificationservice.perehlianuty_tovar')],'generic'),(string)$row['email'],null,'stock-available:'.$public);$this->db->update('mc_stock_notification_request',['status'=>'notified','notified_at'=>gmdate('Y-m-d H:i:s.u'),'updated_at'=>gmdate('Y-m-d H:i:s.u')],['id'=>(int)$row['id']]);}
    }
}
