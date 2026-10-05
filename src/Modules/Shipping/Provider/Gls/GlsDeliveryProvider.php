<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\Gls;

use Commerce\Modules\Shipping\Contract\DeliveryProviderInterface;
use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Commerce\Modules\Shipping\Domain\DeliveryPointType;
use Commerce\Modules\Shipping\Domain\DeliveryProviderCapabilities;
use Commerce\Modules\Shipping\Domain\DeliveryServiceType;
use Commerce\Modules\Shipping\Domain\LocationCatalogMode;

/** GLS ParcelShops: the buyer types the city, the parcel shops of that city are offered (manual address stays available). */
final readonly class GlsDeliveryProvider implements DeliveryProviderInterface
{
    public function __construct(private GlsTransport $transport)
    {
    }

    public function code(): string { return 'gls'; }
    public function label(): string { return 'GLS'; }

    public function capabilities(): DeliveryProviderCapabilities
    {
        return new DeliveryProviderCapabilities(
            serviceTypes: [DeliveryServiceType::PickupPoint, DeliveryServiceType::Courier],
            locationCatalogMode: LocationCatalogMode::CachedQuery,
            supportsManualFallback: true,
            cityCacheTtlSeconds: 3600,
            pointCacheTtlSeconds: 900,
        );
    }

    public function searchCities(DeliveryCitySearch $search): array
    {
        $name = trim($search->query);

        return $name === '' ? [] : [new DeliveryCity($name, $this->code(), $name, $search->countryCode)];
    }

    public function searchPoints(DeliveryPointSearch $search): array
    {
        $locality = trim((string) ($search->cityName ?? $search->cityId ?? $search->query ?? ''));
        if ($locality === '' || !$this->transport->configured()) {
            return [];
        }
        $data = $this->transport->points($search->countryCode, $locality, $search->limit);
        $rows = array_is_list($data) ? $data : [];
        foreach (['parcelShops', 'ParcelShops', 'data', 'items', 'results'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $rows = $data[$key];
                break;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['ParcelShopID'] ?? $row['parcelShopId'] ?? $row['id'] ?? '');
            $name = trim((string) ($row['Name'] ?? $row['name'] ?? $row['Name1'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $a = is_array($row['Address'] ?? null) ? $row['Address'] : $row;
            $street = trim(implode(' ', array_filter([(string) ($a['Street'] ?? $a['street'] ?? ''), (string) ($a['StreetNumber'] ?? $a['streetNumber'] ?? '')])));
            $out[] = new DeliveryPoint(
                id: $id,
                providerCode: $this->code(),
                name: $name,
                city: (string) ($a['City'] ?? $a['city'] ?? $locality),
                type: DeliveryPointType::ServicePoint,
                address: $street !== '' ? $street : null,
                postalCode: isset($a['ZIPCode']) ? (string) $a['ZIPCode'] : (isset($a['zip']) ? (string) $a['zip'] : null),
                countryCode: $search->countryCode,
            );
            if (count($out) >= $search->limit) {
                break;
            }
        }

        return $out;
    }

    public function quote(\Commerce\Modules\Shipping\Domain\DeliveryQuoteRequest $request): array { return []; }
}
