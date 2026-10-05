<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\Dhl;

use Commerce\Modules\Shipping\Contract\DeliveryProviderInterface;
use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Commerce\Modules\Shipping\Domain\DeliveryPointType;
use Commerce\Modules\Shipping\Domain\DeliveryProviderCapabilities;
use Commerce\Modules\Shipping\Domain\DeliveryServiceType;
use Commerce\Modules\Shipping\Domain\LocationCatalogMode;

/**
 * DHL has no settlement directory: the buyer's city is taken as typed and the service points and lockers of that city
 * come from the Location Finder.
 */
final readonly class DhlDeliveryProvider implements DeliveryProviderInterface
{
    public function __construct(private DhlTransport $transport)
    {
    }

    public function code(): string { return 'dhl'; }
    public function label(): string { return 'DHL'; }

    public function capabilities(): DeliveryProviderCapabilities
    {
        return new DeliveryProviderCapabilities(
            serviceTypes: [DeliveryServiceType::PickupPoint, DeliveryServiceType::ParcelLocker, DeliveryServiceType::Courier],
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
        $data = $this->transport->findByAddress($search->countryCode, $locality, $search->limit);
        $out = [];
        foreach ((array) ($data['locations'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids = $row['location']['ids'] ?? [];
            $id = is_array($ids) && isset($ids[0]['locationId']) ? (string) $ids[0]['locationId'] : (string) ($row['url'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $address = is_array($row['place']['address'] ?? null) ? $row['place']['address'] : [];
            $type = strtolower((string) ($row['location']['type'] ?? ''));
            $out[] = new DeliveryPoint(
                id: $id,
                providerCode: $this->code(),
                name: $name,
                city: (string) ($address['addressLocality'] ?? $locality),
                type: str_contains($type, 'locker') ? DeliveryPointType::ParcelLocker : DeliveryPointType::ServicePoint,
                address: isset($address['streetAddress']) ? (string) $address['streetAddress'] : null,
                latitude: isset($row['place']['geo']['latitude']) ? (float) $row['place']['geo']['latitude'] : null,
                longitude: isset($row['place']['geo']['longitude']) ? (float) $row['place']['geo']['longitude'] : null,
                postalCode: isset($address['postalCode']) ? (string) $address['postalCode'] : null,
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
