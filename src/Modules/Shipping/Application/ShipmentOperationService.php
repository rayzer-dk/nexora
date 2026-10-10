<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class ShipmentOperationService
{
    private const STATUSES=['registered','label_ready','in_transit','delivered','exception','returning','returned','cancelled'];
    private const TRANSITIONS=[
        'registered'=>['label_ready','in_transit','cancelled'],
        'label_ready'=>['in_transit','cancelled'],
        'in_transit'=>['delivered','exception','returning'],
        'exception'=>['in_transit','returning','returned','cancelled'],
        'returning'=>['returned','exception'],
        'delivered'=>[], 'returned'=>[], 'cancelled'=>[],
    ];

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> */
    public function register(int $storeId,string $orderPublicId,string $direction,string $providerCode,string $serviceType,string $trackingNumber,string $externalId,string $carrierLabelUrl,string $note,string $actor,?string $returnPublicId=null): array
    {
        $direction=in_array($direction,['outbound','return'],true)?$direction:'outbound';
        $providerCode=$this->clean($providerCode,64);$serviceType=$this->clean($serviceType,64);$trackingNumber=$this->clean($trackingNumber,190);$externalId=$this->clean($externalId,190);$note=mb_substr(trim(strip_tags($note)),0,4000);$carrierLabelUrl=trim($carrierLabelUrl);
        if($providerCode===''||$serviceType===''||$trackingNumber==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.vkazhit_pereviznyka_typ_dostavky_ta_nomer_ttn_tracki'));
        if($carrierLabelUrl!=='' && (!$this->validHttpsUrl($carrierLabelUrl) || mb_strlen($carrierLabelUrl)>2048))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.carrier_label_url_maie_buty_korektnym_https_url'));
        try{$orderBin=Uuid::fromString($orderPublicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.nekorektne_zamovlennia'));}
        return $this->db->transactional(function(Connection $db)use($storeId,$orderBin,$direction,$providerCode,$serviceType,$trackingNumber,$externalId,$carrierLabelUrl,$note,$actor,$returnPublicId):array{
            $order=$db->fetchAssociative('SELECT * FROM mc_sales_order WHERE public_id=? AND store_id=? FOR UPDATE',[$orderBin,$storeId]);
            if(!is_array($order))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zamovlennia_ne_znaideno'));
            if(in_array((string)$order['status'],['cancelled','refunded'],true))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.dlia_skasovanoho_abo_povnistiu_povernutoho_zamovlenn'));
            $fulfillment=$db->fetchAssociative('SELECT * FROM mc_fulfillment WHERE order_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[(int)$order['id']])?:null;
            if($direction==='outbound' && !is_array($fulfillment))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.tse_zamovlennia_ne_potrebuie_fizychnoi_dostavky'));
            $returnId=null;
            if($direction==='return' && $returnPublicId!==null && trim($returnPublicId)!==''){
                try{$retBin=Uuid::fromString($returnPublicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.nekorektnyi_rma'));}
                $returnId=$db->fetchOne('SELECT id FROM mc_return_request WHERE public_id=? AND store_id=? AND order_id=?',[$retBin,$storeId,(int)$order['id']]);
                if(!$returnId)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.rma_ne_znaideno_dlia_tsoho_zamovlennia'));
            }
            $dup=$db->fetchOne('SELECT id FROM mc_shipment WHERE store_id=? AND provider_code=? AND tracking_number=? AND direction=?',[$storeId,$providerCode,$trackingNumber,$direction]);
            if($dup)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.take_vidpravlennia_vzhe_zareiestrovano'));
            $now=$this->now();$public=Uuid::v7();
            $snapshot=['order_number'=>(string)$order['order_number'],'customer_name'=>(string)$order['customer_name'],'customer_phone'=>(string)$order['customer_phone'],'customer_email'=>(string)$order['customer_email'],'currency'=>(string)$order['currency'],'destination'=>is_array($fulfillment)?$this->json((string)$fulfillment['destination_snapshot']):[]];
            $db->insert('mc_shipment',['public_id'=>$public->toBinary(),'store_id'=>$storeId,'order_id'=>(int)$order['id'],'fulfillment_id'=>is_array($fulfillment)?(int)$fulfillment['id']:null,'return_request_id'=>$returnId?:null,'direction'=>$direction,'provider_code'=>$providerCode,'service_type'=>$serviceType,'status'=>'registered','external_id'=>$externalId!==''?$externalId:null,'tracking_number'=>$trackingNumber,'carrier_label_url'=>$carrierLabelUrl!==''?$carrierLabelUrl:null,'snapshot_json'=>json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'note'=>$note!==''?$note:null,'created_by'=>$actor,'created_at'=>$now,'updated_at'=>$now]);
            $id=(int)$db->lastInsertId();$this->event($db,$id,'shipment.registered',$actor,['direction'=>$direction,'tracking_number'=>$trackingNumber]);
            if($direction==='outbound' && is_array($fulfillment)){
                $db->update('mc_fulfillment',['tracking_number'=>$trackingNumber,'status'=>'shipped','updated_at'=>$now],['id'=>(int)$fulfillment['id']]);
                $db->update('mc_sales_order',['fulfillment_status'=>'shipped','updated_at'=>$now],['id'=>(int)$order['id']]);
            } elseif($direction==='return' && $returnId){
                $db->update('mc_return_request',['return_tracking_number'=>$trackingNumber,'updated_at'=>$now],['id'=>(int)$returnId]);
            }
            return ['public_id'=>$public->toRfc4122(),'tracking_number'=>$trackingNumber,'status'=>'registered'];
        });
    }

    public function updateStatus(int $storeId,string $shipmentPublicId,string $status,string $actor,bool $carrierConfirmed=false): void
    {
        if(!in_array($status,self::STATUSES,true))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.nekorektnyi_status_vidpravlennia'));
        try{$bin=Uuid::fromString($shipmentPublicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.nekorektne_vidpravlennia'));}
        $this->db->transactional(function(Connection $db)use($storeId,$bin,$status,$actor,$carrierConfirmed):void{
            $row=$db->fetchAssociative('SELECT s.*,o.id order_pk FROM mc_shipment s JOIN mc_sales_order o ON o.id=s.order_id WHERE s.public_id=? AND s.store_id=? FOR UPDATE',[$bin,$storeId]);if(!is_array($row))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.vidpravlennia_ne_znaideno'));
            $current=(string)$row['status'];if($current===$status)return;
            if($status==='cancelled'&&!$carrierConfirmed&&trim((string)($row['external_id']??''))!=='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.vidpravlennia_stvorene_u_systemi_pereviznyka_spochat'));
            if(!in_array($status,self::TRANSITIONS[$current]??[],true))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.nedopustymyi_perekhid_statusu').$current.' → '.$status.'.');
            $now=$this->now();$fields=['status'=>$status,'updated_at'=>$now];if($status==='cancelled')$fields['cancelled_at']=$now;if($status==='delivered')$fields['delivered_at']=$now;$db->update('mc_shipment',$fields,['id'=>(int)$row['id']]);
            $this->event($db,(int)$row['id'],'shipment.status_changed',$actor,['from'=>$current,'to'=>$status]);
            if((string)$row['direction']==='outbound' && $row['fulfillment_id']){
                $fulfillmentStatus=match($status){'in_transit'=>'shipped','delivered'=>'delivered','cancelled'=>'cancelled',default=>null};
                if($fulfillmentStatus!==null){$db->update('mc_fulfillment',['status'=>$fulfillmentStatus,'tracking_number'=>(string)$row['tracking_number'],'updated_at'=>$now],['id'=>(int)$row['fulfillment_id']]);$db->update('mc_sales_order',['fulfillment_status'=>$fulfillmentStatus,'updated_at'=>$now],['id'=>(int)$row['order_id']]);}
            }
        });
    }



    /** @param array<string,mixed> $payload */
    /** Corrects a tracking number typed by hand (not one the carrier created); the change is dated, signed and kept in the shipment history. */
    public function updateTracking(int $storeId,string $shipmentPublicId,string $tracking,string $actor): void
    {
        $tracking=$this->clean($tracking,190);
        if($tracking==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.shipments.error.tracking_required'));
        try{$bin=Uuid::fromString($shipmentPublicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.nekorektne_vidpravlennia'));}
        $this->db->transactional(function(Connection $db)use($storeId,$bin,$tracking,$actor):void{
            $row=$db->fetchAssociative('SELECT * FROM mc_shipment WHERE public_id=? AND store_id=? FOR UPDATE',[$bin,$storeId]);
            if(!is_array($row))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.nekorektne_vidpravlennia'));
            if(trim((string)($row['external_id']??''))!=='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.shipments.error.tracking_carrier'));
            if((string)$row['tracking_number']===$tracking)return;
            $taken=$db->fetchOne('SELECT 1 FROM mc_shipment WHERE store_id=? AND provider_code=? AND tracking_number=? AND direction=? AND id<>?',[$storeId,$row['provider_code'],$tracking,$row['direction'],(int)$row['id']]);
            if($taken!==false)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.shipments.error.tracking_taken'));
            $now=$this->now();
            $db->update('mc_shipment',['tracking_number'=>$tracking,'tracking_edited_at'=>$now,'tracking_edited_by'=>$this->clean($actor,190),'updated_at'=>$now],['id'=>(int)$row['id']]);
            if($row['fulfillment_id'])$db->update('mc_fulfillment',['tracking_number'=>$tracking,'updated_at'=>$now],['id'=>(int)$row['fulfillment_id']]);
            $this->event($db,(int)$row['id'],'shipment.tracking_edited',$actor,['from'=>(string)$row['tracking_number'],'to'=>$tracking]);
        });
    }

    public function recordProviderEvent(int $storeId,string $shipmentPublicId,string $type,array $payload,string $actor='system'): void
    {
        try{$bin=Uuid::fromString($shipmentPublicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.nekorektne_vidpravlennia'));}
        $row=$this->db->fetchAssociative('SELECT id FROM mc_shipment WHERE public_id=? AND store_id=?',[$bin,$storeId]);
        if(!is_array($row))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.vidpravlennia_ne_znaideno'));
        $clean=[];foreach($payload as $k=>$v){if(is_scalar($v)||$v===null)$clean[mb_substr((string)$k,0,80)]=$v;}
        $this->event($this->db,(int)$row['id'],'provider.'.preg_replace('/[^a-z0-9_.-]+/i','_',mb_substr($type,0,80)),$actor,$clean);
    }

    /** @param list<string> $shipmentPublicIds */
    public function bulkStatus(int $storeId,array $shipmentPublicIds,string $status,string $actor): int
    {
        $done=0;foreach(array_values(array_unique($shipmentPublicIds)) as $id){try{$this->updateStatus($storeId,(string)$id,$status,$actor);$done++;}catch(\DomainException){continue;}}return $done;
    }

    /** @return list<array<string,mixed>> */
    public function listForOrder(int $storeId,string $orderPublicId): array
    {
        try{$bin=Uuid::fromString($orderPublicId)->toBinary();}catch(\Throwable){return [];}
        $rows=$this->db->fetchAllAssociative('SELECT s.* FROM mc_shipment s JOIN mc_sales_order o ON o.id=s.order_id WHERE o.public_id=? AND s.store_id=? ORDER BY s.id DESC',[$bin,$storeId]);
        foreach($rows as &$row){$row['public_id_text']=Uuid::fromBinary((string)$row['public_id'])->toRfc4122();}unset($row);return $rows;
    }

    /** @return array<string,mixed> */
    public function get(int $storeId,string $shipmentPublicId): array
    {
        try{$bin=Uuid::fromString($shipmentPublicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.nekorektne_vidpravlennia'));}
        $row=$this->db->fetchAssociative('SELECT s.*,o.order_number,o.customer_name,o.customer_phone,o.customer_email FROM mc_shipment s JOIN mc_sales_order o ON o.id=s.order_id WHERE s.public_id=? AND s.store_id=?',[$bin,$storeId]);if(!is_array($row))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.shipmentoperationservice.vidpravlennia_ne_znaideno'));$row['public_id_text']=$shipmentPublicId;$row['snapshot']=$this->json((string)$row['snapshot_json']);$row['events']=$this->db->fetchAllAssociative('SELECT event_type,actor,payload_json,created_at FROM mc_shipment_event WHERE shipment_id=? ORDER BY id DESC',[(int)$row['id']]);foreach($row['events'] as &$event){$event['payload']=$this->json((string)($event['payload_json']??''));}unset($event);return $row;
    }

    /** @return list<array<string,mixed>> */
    public function listForStore(int $storeId,string $status='',int $limit=100): array
    {
        $params=[$storeId];$where='s.store_id=?';if(in_array($status,self::STATUSES,true)){$where.=' AND s.status=?';$params[]=$status;}
        $rows=$this->db->fetchAllAssociative('SELECT s.*,o.order_number,o.public_id AS order_public_id_bin FROM mc_shipment s JOIN mc_sales_order o ON o.id=s.order_id WHERE '.$where.' ORDER BY s.id DESC LIMIT '.max(1,min(250,$limit)),$params);foreach($rows as &$r){$r['public_id_text']=Uuid::fromBinary((string)$r['public_id'])->toRfc4122();$r['order_public_id']=Uuid::fromBinary((string)$r['order_public_id_bin'])->toRfc4122();unset($r['order_public_id_bin']);}unset($r);return $rows;
    }

    private function event(Connection $db,int $shipmentId,string $type,string $actor,array $payload):void{$db->insert('mc_shipment_event',['shipment_id'=>$shipmentId,'event_type'=>$type,'actor'=>$actor,'payload_json'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'created_at'=>$this->now()]);}
    private function clean(string $v,int $max):string{return mb_substr(trim(strip_tags($v)),0,$max);}
    private function validHttpsUrl(string $url):bool{$p=parse_url($url);return is_array($p)&&strtolower((string)($p['scheme']??''))==='https'&&isset($p['host']);}
    private function now():string{return gmdate('Y-m-d H:i:s.u');}
    /** @return array<string,mixed> */ private function json(string $json):array{try{$v=json_decode($json,true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return [];}}
}
