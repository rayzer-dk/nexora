<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Commerce\Modules\Automation\Application\AutomationEngine;
use Symfony\Component\Uid\Uuid;

final readonly class CustomerInquiryService
{
    public function __construct(private Connection $db,private PublicIdFactory $ids,private NotificationOutbox $notifications,private ?AutomationEngine $automation=null,private ?\Commerce\Modules\Notification\Application\TelegramAlertSettings $telegram=null) {}

    /** @param array<string,mixed> $input */
    public function create(int $storeId,array $input,?string $productPublicId=null): string
    {
        $type=trim((string)($input['inquiry_type']??'contact'));if(!in_array($type,['contact','callback','product_question','price_request','quick_order'],true))$type='contact';
        $name=trim((string)($input['name']??''));$email=mb_strtolower(trim((string)($input['email']??'')));$phone=trim((string)($input['phone']??''));$message=trim((string)($input['message']??''));$source=trim((string)($input['source_url']??''));
        if($name===''||mb_strlen($name)>190)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.vkazhit_imia'));
        if($email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.nekorektnyi_email'));
        if($phone===''&&$email==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.vkazhit_telefon_abo_email'));
        if(mb_strlen($phone)>32||mb_strlen($message)>4000)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.perevirte_vvedeni_dani'));
        if($type==='quick_order'){if($phone==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.vkazhit_telefon_abo_email'));$qty=preg_replace('/[^0-9.,]/','',(string)($input['quantity']??'1'))?:'1';$sku=preg_replace('/[^A-Za-z0-9._-]/','',(string)($input['sku']??''));$comment=mb_substr($message,0,1000);$message='× '.mb_substr($qty,0,12).($sku!==''?' · '.mb_substr($sku,0,64):'').($comment!==''?"\n".$comment:'');}
        if($message===''&&$type!=='callback')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.napyshit_povidomlennia'));
        if(strlen($source)>1000)$source=substr($source,0,1000);
        $productId=null;$productName=null;
        if($productPublicId!==null){if(!Uuid::isValid($productPublicId))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.nekorektnyi_tovar'));$p=$this->db->fetchAssociative('SELECT p.id,pt.name FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=(SELECT default_locale FROM mc_store WHERE id=?) WHERE p.public_id=? LIMIT 1',[$storeId,$storeId,$storeId,Uuid::fromString($productPublicId)->toBinary()]);if(!is_array($p))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.tovar_ne_znaideno'));$productId=(int)$p['id'];$productName=(string)($p['name']??'');}
        $public=$this->ids->generate();$now=gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_customer_inquiry',['public_id'=>$public->toBinary(),'store_id'=>$storeId,'product_id'=>$productId,'inquiry_type'=>$type,'status'=>'new','customer_name'=>$name,'email'=>$email!==''?$email:null,'phone'=>$phone!==''?$phone:null,'message'=>$message,'source_url'=>$source!==''?$source:null,'admin_note'=>null,'created_at'=>$now,'updated_at'=>$now]);
        if($this->telegram!==null){$typeLabel=\Commerce\Core\I18n\CanonicalUiText::get('notify.tg.inquiry_type.'.$type);$tgText=trim($name.' '.($phone!==''?$phone:'').' '.($email!==''?$email:''));if($productName!==null&&$productName!=='')$tgText.="\n".$productName;if($message!=='')$tgText.="\n".mb_substr($message,0,300);$this->telegram->alert('inquiry',\Commerce\Core\I18n\CanonicalUiText::get('notify.tg.inquiry').' · '.$typeLabel,$tgText,'inquiry-tg:'.$public->toRfc4122());}
        $manager=(string)$this->db->fetchOne('SELECT COALESCE(NULLIF(email,\'\'),NULLIF(privacy_contact,\'\')) FROM mc_store_profile WHERE store_id=? LIMIT 1',[$storeId]);
        if($manager!==''&&filter_var($manager,FILTER_VALIDATE_EMAIL)!==false){$subject=$type==='price_request'?\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.zapyt_tsiny').($productName?' — '.$productName:''):\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.nove_zvernennia_pokuptsia');$productLine=$productName!==null&&$productName!==''?\Commerce\Core\I18n\CanonicalUiText::get('customer.inquiry.product_line',['product'=>$productName]):'';$text=\Commerce\Core\I18n\CanonicalUiText::get('customer.inquiry.manager_text',['name'=>$name,'phone'=>$phone?:'—','email'=>$email?:'—','product'=>$productLine,'message'=>$message]);$this->notifications->enqueue(NotificationChannel::Email,new NotificationMessage('customer_inquiry',$subject,$text,[],'generic'),$manager,null,'inquiry-manager:'.$public->toRfc4122());}
        if($email!==''){$this->notifications->enqueue(NotificationChannel::Email,new NotificationMessage('inquiry_received',\Commerce\Core\I18n\CanonicalUiText::get('customer.inquiry.received_subject'),\Commerce\Core\I18n\CanonicalUiText::get('customer.inquiry.received_text'),[],'generic'),$email,null,'inquiry-customer:'.$public->toRfc4122());}
        try{$this->automation?->fire($storeId,'inquiry_created','inquiry:'.$public->toRfc4122(),['name'=>$name,'text'=>$name.($phone!==''?' · '.$phone:'').' · '.$type.($message!==''?' · '.mb_substr($message,0,120):''),'url'=>'/admin/commerce/inquiries']);}catch(\Throwable){}
        return $public->toRfc4122();
    }
}
