<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\Ukrposhta;

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
use InvalidArgumentException;

final readonly class UkrposhtaDeliveryProvider implements DeliveryProviderInterface
{
    public function __construct(private UkrposhtaTransport $transport)
    {
    }

    public function code(): string { return 'ukrposhta'; }
    public function label(): string { return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.ukrposhta.ukrposhtadeliveryprovider.ukrposhta'); }

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

        $data = $this->transport->get('/get_city_by_name', [
            'city_name' => trim($search->query),
            'lang' => 'UA',
            'fuzzy' => 1,
        ]);

        $cities = [];
        foreach ($this->entries($data) as $row) {
            $id = $row['CITY_ID'] ?? null;
            $name = $row['CITY_NAME'] ?? $row['CITY_UA'] ?? null;
            if (!is_scalar($id) || !is_string($name) || trim($name) === '') {
                continue;
            }
            $cities[] = new DeliveryCity(
                id: (string) $id,
                providerCode: $this->code(),
                name: $name,
                countryCode: 'UA',
                region: is_string($row['REGION_NAME'] ?? null) ? $row['REGION_NAME'] : (is_string($row['REGION_UA'] ?? null) ? $row['REGION_UA'] : null),
                district: is_string($row['DISTRICT_NAME'] ?? null) ? $row['DISTRICT_NAME'] : (is_string($row['DISTRICT_UA'] ?? null) ? $row['DISTRICT_UA'] : null),
                metadata: [
                    'region_id' => is_scalar($row['REGION_ID'] ?? null) ? (string) $row['REGION_ID'] : null,
                    'district_id' => is_scalar($row['DISTRICT_ID'] ?? null) ? (string) $row['DISTRICT_ID'] : null,
                ],
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

        $data = $this->transport->get('/get_postoffices_by_postcode_cityid_cityvpzid', [
            'city_id' => $search->cityId,
        ]);

        $points = [];
        foreach ($this->entries($data) as $row) {
            if ((string) ($row['LOCK_CODE'] ?? '0') !== '0' || (string) ($row['IS_SECURITY'] ?? '0') === '1') {
                continue;
            }
            $id = $row['POSTOFFICE_ID'] ?? null;
            $postcode = $row['POSTCODE'] ?? null;
            $name = $row['POSTOFFICE_UA'] ?? null;
            if (!is_scalar($id) || !is_string($name)) {
                continue;
            }
            $address = is_string($row['STREET_UA_VPZ'] ?? null) ? $row['STREET_UA_VPZ'] : null;
            $points[] = new DeliveryPoint(
                id: (string) $id,
                providerCode: $this->code(),
                name: $name,
                city: is_string($row['CITY_UA'] ?? null) ? $row['CITY_UA'] : ($search->cityName ?? ''),
                type: DeliveryPointType::Branch,
                address: $address,
                latitude: $this->floatOrNull($row['LATTITUDE'] ?? null),
                longitude: $this->floatOrNull($row['LONGITUDE'] ?? null),
                postalCode: is_scalar($postcode) ? (string) $postcode : null,
                countryCode: 'UA',
                metadata: ['postterminal' => (string) ($row['POSTTERMINAL'] ?? '0')],
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
    private function entries(array $data): array
    {
        $entries = $data['Entries']['Entry'] ?? [];
        if (!is_array($entries)) {
            return [];
        }
        if ($entries !== [] && !array_is_list($entries)) {
            $entries = [$entries];
        }
        return array_values(array_filter($entries, 'is_array'));
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
