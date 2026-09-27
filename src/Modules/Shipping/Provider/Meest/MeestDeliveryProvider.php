<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\Meest;

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

final readonly class MeestDeliveryProvider implements DeliveryProviderInterface
{
    public function __construct(private MeestTransport $transport)
    {
    }

    public function code(): string { return 'meest'; }
    public function label(): string { return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.shipping.provider.meest.meestdeliveryprovider.meest_poshta'); }

    public function capabilities(): DeliveryProviderCapabilities
    {
        return new DeliveryProviderCapabilities(
            serviceTypes: [DeliveryServiceType::PickupPoint, DeliveryServiceType::ParcelLocker, DeliveryServiceType::Courier],
            locationCatalogMode: LocationCatalogMode::CachedQuery,
            supportsManualFallback: true,
            cityCacheTtlSeconds: 21600,
            pointCacheTtlSeconds: 600,
        );
    }

    public function searchCities(DeliveryCitySearch $search): array
    {
        if ($search->countryCode !== 'UA') { return []; }
        $data = $this->transport->cities($search->query, $search->limit);
        $rows = $this->rows($data);
        $out = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? $row['city_id'] ?? $row['cityId'] ?? null;
            $name = $row['name'] ?? $row['city_name'] ?? $row['cityName'] ?? null;
            if (!is_scalar($id) || !is_string($name)) { continue; }
            $out[] = new DeliveryCity((string) $id, $this->code(), $name, 'UA', is_string($row['region'] ?? null) ? $row['region'] : null);
            if (count($out) >= $search->limit) { break; }
        }
        return $out;
    }

    public function searchPoints(DeliveryPointSearch $search): array
    {
        if ($search->countryCode !== 'UA' || $search->cityId === null) { return []; }
        $data = $this->transport->points($search->cityId, $search->limit);
        $rows = $this->rows($data);
        $out = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? $row['branch_id'] ?? $row['branchId'] ?? null;
            $name = $row['name'] ?? $row['branch_name'] ?? $row['branchName'] ?? null;
            if (!is_scalar($id) || !is_string($name)) { continue; }
            $isLocker = (bool) ($row['is_locker'] ?? $row['isLocker'] ?? false);
            $out[] = new DeliveryPoint(
                id: (string) $id,
                providerCode: $this->code(),
                name: $name,
                city: $search->cityName ?? '',
                type: $isLocker ? DeliveryPointType::ParcelLocker : DeliveryPointType::Branch,
                address: is_string($row['address'] ?? null) ? $row['address'] : null,
                countryCode: 'UA',
            );
            if (count($out) >= $search->limit) { break; }
        }
        return $out;
    }

    public function quote(DeliveryQuoteRequest $request): array { return []; }

    /** @param array<mixed> $data
     *  @return list<array<mixed>>
     */
    private function rows(array $data): array
    {
        foreach (['data', 'items', 'result', 'locations'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        return array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
    }
}
