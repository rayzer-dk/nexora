<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\DeliveryAuto;

use Commerce\Modules\Shipping\Contract\DeliveryProviderInterface;
use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Commerce\Modules\Shipping\Domain\DeliveryPointType;
use Commerce\Modules\Shipping\Domain\DeliveryProviderCapabilities;
use Commerce\Modules\Shipping\Domain\DeliveryQuote;
use Commerce\Modules\Shipping\Domain\DeliveryQuoteRequest;
use Commerce\Modules\Shipping\Domain\DeliveryServiceType;
use Commerce\Modules\Shipping\Domain\LocationCatalogMode;

final readonly class DeliveryAutoDeliveryProvider implements DeliveryProviderInterface
{
    public function __construct(private DeliveryAutoTransport $transport)
    {
    }

    public function code(): string { return 'delivery_auto'; }
    public function label(): string { return 'Delivery'; }

    public function capabilities(): DeliveryProviderCapabilities
    {
        return new DeliveryProviderCapabilities(
            serviceTypes: [DeliveryServiceType::PickupPoint, DeliveryServiceType::Courier],
            locationCatalogMode: LocationCatalogMode::CachedQuery,
            supportsManualFallback: true,
            cityCacheTtlSeconds: 21600,
            pointCacheTtlSeconds: 600,
        );
    }

    public function searchCities(DeliveryCitySearch $search): array
    {
        if ($search->countryCode !== 'UA') {
            return [];
        }
        $data = $this->transport->get('GetAreasList', [
            'culture' => 'uk-UA',
            'fl_all' => 'true',
            'country' => 1,
            'cityName' => trim($search->query),
        ]);
        $rows = $this->rows($data);
        $cities = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_scalar($id) || !is_string($name) || trim($name) === '') {
                continue;
            }
            $cities[] = new DeliveryCity(
                id: (string) $id,
                providerCode: $this->code(),
                name: $name,
                countryCode: 'UA',
                region: is_string($row['regionName'] ?? null) ? $row['regionName'] : null,
                district: is_string($row['districtName'] ?? null) ? $row['districtName'] : null,
            );
            if (count($cities) >= $search->limit) {
                break;
            }
        }
        return $cities;
    }

    public function searchPoints(DeliveryPointSearch $search): array
    {
        if ($search->countryCode !== 'UA' || $search->cityId === null) {
            return [];
        }
        $data = $this->transport->get('GetWarehousesListByCity', [
            'CityId' => $search->cityId,
            'DirectionType' => 1,
            'culture' => 'uk-UA',
        ]);
        $points = [];
        foreach ($this->rows($data) as $row) {
            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_scalar($id) || !is_string($name)) {
                continue;
            }
            $points[] = new DeliveryPoint(
                id: (string) $id,
                providerCode: $this->code(),
                name: $name,
                city: is_string($row['CityName'] ?? null) ? $row['CityName'] : ($search->cityName ?? ''),
                type: DeliveryPointType::Branch,
                address: is_string($row['address'] ?? null) ? $row['address'] : null,
                latitude: $this->floatOrNull($row['LatitudeCorrect'] ?? $row['latitudeCorrect'] ?? null),
                longitude: $this->floatOrNull($row['LongitudeCorrect'] ?? $row['longitudeCorrect'] ?? null),
                countryCode: 'UA',
                metadata: [
                    'operating_time' => is_string($row['operatingTime'] ?? null) ? $row['operatingTime'] : null,
                    'cash_on_delivery' => (bool) ($row['IsCashOnDelivery'] ?? false),
                ],
            );
            if (count($points) >= $search->limit) {
                break;
            }
        }
        return $points;
    }

    public function quote(DeliveryQuoteRequest $request): array { return []; }

    /** @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private function rows(array $data): array
    {
        foreach (['data', 'Data', 'items', 'result'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        return array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
