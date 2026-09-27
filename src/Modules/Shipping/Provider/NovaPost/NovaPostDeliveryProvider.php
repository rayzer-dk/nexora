<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\NovaPost;

use Commerce\Modules\Shipping\Contract\DeliveryProviderInterface;
use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Commerce\Modules\Shipping\Domain\DeliveryPointType;
use Commerce\Modules\Shipping\Domain\DeliveryProviderCapabilities;
use Commerce\Modules\Shipping\Domain\DeliveryQuoteRequest;
use Commerce\Modules\Shipping\Domain\LocationCatalogMode;
use Commerce\Modules\Shipping\Domain\DeliveryServiceType;

final readonly class NovaPostDeliveryProvider implements DeliveryProviderInterface
{
    public function __construct(private NovaPostTransport $transport) {}

    public function code(): string { return 'nova_post'; }
    public function label(): string { return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.novapostdeliveryprovider.nova_poshta'); }

    public function capabilities(): DeliveryProviderCapabilities
    {
        return new DeliveryProviderCapabilities(
            serviceTypes: [DeliveryServiceType::PickupPoint, DeliveryServiceType::ParcelLocker, DeliveryServiceType::Courier],
            locationCatalogMode: LocationCatalogMode::CachedQuery,
            supportsLiveRates: false,
            supportsShipmentCreation: true,
            supportsTracking: true,
            supportsReturns: false,
            supportsWebhooks: false,
        );
    }

    public function searchCities(DeliveryCitySearch $search): array
    {
        if (strtoupper($search->countryCode) !== 'UA') return [];
        $response = $this->transport->call('Address', 'getCities', [
            'FindByString' => trim($search->query),
            'Limit' => min(100, max(1, $search->limit)),
            'Page' => 1,
        ]);
        $cities=[];
        foreach($this->transport->rows($response) as $row){
            $ref=$this->scalar($row['Ref']??null);$name=$this->scalar($row['Description']??null);
            if($ref===''||$name==='')continue;
            $cities[]=new DeliveryCity($ref,$this->code(),$name,'UA',$this->nullable($row['AreaDescription']??null));
        }
        return array_slice($cities,0,$search->limit);
    }

    public function searchPoints(DeliveryPointSearch $search): array
    {
        if (strtoupper($search->countryCode) !== 'UA' || trim((string)$search->cityId)==='') return [];
        $properties=['CityRef'=>$search->cityId,'Limit'=>min(100,max(1,$search->limit)),'Page'=>1];
        if(trim((string)$search->query)!=='')$properties['FindByString']=trim((string)$search->query);
        $response=$this->transport->call('AddressGeneral','getWarehouses',$properties);
        $points=[];
        foreach($this->transport->rows($response) as $row){
            $ref=$this->scalar($row['Ref']??null);$name=$this->scalar($row['Description']??null);
            if($ref===''||$name==='')continue;
            $type=$this->pointType($row);
            if($search->types!==[]&&!in_array($type,$search->types,true))continue;
            $points[]=new DeliveryPoint(
                id:$ref,providerCode:$this->code(),name:$name,city:$this->scalar($row['CityDescription']??$search->cityName??''),type:$type,
                address:$this->nullable($row['ShortAddress']??$row['Description']??null),postalCode:$this->nullable($row['PostFinance']??null),countryCode:'UA',
                metadata:['number'=>$this->scalar($row['Number']??null),'warehouse_type_ref'=>$this->scalar($row['TypeOfWarehouse']??null),'category'=>$this->scalar($row['CategoryOfWarehouse']??null)]
            );
        }
        return array_slice($points,0,$search->limit);
    }

    public function quote(DeliveryQuoteRequest $request): array { return []; }

    /** @param array<string,mixed> $row */
    private function pointType(array $row): DeliveryPointType
    {
        $haystack=mb_strtolower($this->scalar($row['Description']??'').' '.$this->scalar($row['CategoryOfWarehouse']??''));
        if(str_contains($haystack,\Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.novapost.novapostdeliveryprovider.poshtomat')))return DeliveryPointType::ParcelLocker;
        return DeliveryPointType::Branch;
    }
    private function scalar(mixed $v):string{return is_scalar($v)?trim((string)$v):'';}
    private function nullable(mixed $v):?string{$s=$this->scalar($v);return $s===''?null:$s;}
}
