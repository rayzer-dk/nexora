<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Commerce\Modules\Shipping\Provider\NovaPost\NovaPostShipmentGateway;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class NovaPostShipmentOperationService
{
    public function __construct(
        private Connection $db,
        private NovaPostShipmentGateway $gateway,
        private ShipmentOperationService $shipments,
    ) {}

    public function configured(): bool { return $this->gateway->configured(); }
    public function labelConfigured(): bool { return $this->gateway->labelConfigured(); }

    /** @return array<string,mixed> */
    public function create(int $storeId,string $orderPublicId,float $weightKg,string $description,string $actor): array
    {
        if($weightKg<=0||$weightKg>1000)throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.vaha_vidpravlennia_maie_buty_bilshoiu_za_0_ta_ne_bil'));
        try{$bin=Uuid::fromString($orderPublicId)->toBinary();}catch(\Throwable){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.nekorektne_zamovlennia'));}
        $row=$this->db->fetchAssociative('SELECT o.*,f.provider_code,f.service_type,f.destination_snapshot,p.provider_code AS payment_provider,p.status AS payment_state FROM mc_sales_order o JOIN mc_fulfillment f ON f.order_id=o.id LEFT JOIN mc_payment p ON p.id=(SELECT p2.id FROM mc_payment p2 WHERE p2.order_id=o.id ORDER BY p2.id DESC LIMIT 1) WHERE o.public_id=? AND o.store_id=? ORDER BY f.id DESC LIMIT 1',[$bin,$storeId]);
        if(!is_array($row))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.zamovlennia_abo_fulfillment_ne_znaideno'));
        if((string)$row['provider_code']!=='nova_post')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.avtomatychne_stvorennia_ttn_dostupne_lyshe_dlia_zamo'));
        if((string)$row['currency']!=='UAH')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.avtomatychne_stvorennia_ukrainskoi_ttn_zaraz_pidtrym'));
        if(!in_array((string)$row['service_type'],['pickup_point','parcel_locker'],true))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.avtomatychne_stvorennia_ttn_zaraz_pidtrymuie_viddile'));
        $destination=$this->json((string)$row['destination_snapshot']);$cityRef=trim((string)($destination['city_id']??''));$addressRef=trim((string)($destination['point_id']??''));
        if($cityRef===''||$addressRef==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.u_zamovlenni_nemaie_perevirenykh_nova_poshta_cityref'));
        $cod=(string)($row['payment_provider']??'')==='cash_on_delivery' && (string)($row['payment_state']??'')!=='paid' ? ((int)$row['total_minor']/100) : 0.0;
        $created=null;
        try{
            $created=$this->gateway->create([
                'recipient_name'=>(string)$row['customer_name'],'recipient_phone'=>(string)$row['customer_phone'],'recipient_email'=>(string)($row['customer_email']??''),
                'city_ref'=>$cityRef,'address_ref'=>$addressRef,'description'=>$description!==''?$description:(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.zamovlennia').(string)$row['order_number']),
                'weight_kg'=>$weightKg,'cost_uah'=>max(1,(int)$row['total_minor']/100),'cod_uah'=>$cod,
            ]);
            return $this->shipments->register($storeId,$orderPublicId,'outbound','nova_post',(string)$row['service_type'],(string)$created['tracking_number'],(string)$created['external_id'],'',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.stvoreno_avtomatychno_cherez_nova_poshta_api_2_0'),$actor,null);
        } catch(\Throwable $e){
            if(is_array($created)&&trim((string)($created['external_id']??''))!==''){try{$this->gateway->cancel((string)$created['external_id']);}catch(\Throwable){}}
            if($e instanceof \DomainException)throw $e;
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.provider_error', ['message' => $e->getMessage()]),0,$e);
        }
    }

    public function cancel(int $storeId,string $shipmentPublicId,string $actor): void
    {
        $shipment=$this->shipments->get($storeId,$shipmentPublicId);
        if((string)$shipment['provider_code']!=='nova_post'||trim((string)($shipment['external_id']??''))==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.tse_vidpravlennia_ne_maie_nova_poshta_document_ref'));
        if(in_array((string)$shipment['status'],['delivered','returned','cancelled'],true))throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.tse_vidpravlennia_vzhe_ne_mozhna_skasuvaty'));
        try{$this->gateway->cancel((string)$shipment['external_id']);}catch(\Throwable $e){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.provider_error', ['message' => $e->getMessage()]),0,$e);}
        $this->shipments->updateStatus($storeId,$shipmentPublicId,'cancelled',$actor,true);
    }

    /** @return array<string,mixed> */
    public function tracking(int $storeId,string $shipmentPublicId): array
    {
        $shipment=$this->shipments->get($storeId,$shipmentPublicId);
        if((string)$shipment['provider_code']!=='nova_post')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.tracking_api_dostupnyi_dlia_nova_poshta_shipment'));
        try{$data=$this->gateway->track((string)$shipment['tracking_number'],(string)($shipment['customer_phone']??''));$this->shipments->recordProviderEvent($storeId,$shipmentPublicId,'tracking_checked',$data,'system');return $data;}catch(\Throwable $e){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.provider_error', ['message' => $e->getMessage()]),0,$e);}
    }

    public function labelPdf(int $storeId,string $shipmentPublicId): string
    {
        $shipment=$this->shipments->get($storeId,$shipmentPublicId);$ref=trim((string)($shipment['external_id']??''));
        if((string)$shipment['provider_code']!=='nova_post'||$ref==='')throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.application.novapostshipmentoperationservice.carrier_label_nedostupnyi_dlia_tsoho_vidpravlennia'));
        try{return $this->gateway->labelPdf($ref);}catch(\Throwable $e){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.gateway.provider_error', ['message' => $e->getMessage()]),0,$e);}
    }

    /** @return array<string,mixed> */ private function json(string $json):array{try{$v=json_decode($json,true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return [];}}
}
