<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Provider\SelfPickup;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Shipping\Contract\DeliveryProviderInterface;
use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Commerce\Modules\Shipping\Domain\DeliveryPointType;
use Commerce\Modules\Shipping\Domain\DeliveryProviderCapabilities;
use Commerce\Modules\Shipping\Domain\DeliveryQuoteRequest;
use Commerce\Modules\Shipping\Domain\DeliveryServiceType;
use Commerce\Modules\Shipping\Domain\LocationCatalogMode;
use Commerce\Modules\Storefront\Infrastructure\PickupPointRepository;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\RequestStack;

/** "Self pickup": the customer collects the order at one of the store's own pickup points (no carrier, no shipment). */
final readonly class SelfPickupDeliveryProvider implements DeliveryProviderInterface
{
    public const CODE = 'self_pickup';

    public function __construct(
        private PickupPointRepository $points,
        private StorefrontContextResolver $contexts,
        private RequestStack $requests,
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return CanonicalUiText::get('shipping.self_pickup');
    }

    public function capabilities(): DeliveryProviderCapabilities
    {
        return new DeliveryProviderCapabilities(
            serviceTypes: [DeliveryServiceType::StorePickup],
            locationCatalogMode: LocationCatalogMode::LiveQuery,
            supportsManualFallback: false,
            cityCacheTtlSeconds: 0,
            pointCacheTtlSeconds: 0,
        );
    }

    /** @return list<DeliveryCity> */
    public function searchCities(DeliveryCitySearch $search): array
    {
        return [];
    }

    /** @return list<DeliveryPoint> */
    public function searchPoints(DeliveryPointSearch $search): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return [];
        }
        $context = $this->contexts->resolve($request);
        $out = [];
        foreach ($this->points->forCheckout($context->storeId, $context->storeName) as $point) {
            $out[] = new DeliveryPoint(
                id: (string) $point['id'],
                providerCode: self::CODE,
                name: $point['name'],
                city: $point['city'],
                type: DeliveryPointType::Store,
                address: $point['address'] !== '' ? $point['address'] : null,
                postalCode: null,
                countryCode: strtoupper($context->countryCode),
                metadata: ['working_hours' => $point['working_hours'], 'phone' => $point['phone']],
            );
        }

        return $out;
    }

    /** @return list<\Commerce\Modules\Shipping\Domain\DeliveryQuote> */
    public function quote(DeliveryQuoteRequest $request): array
    {
        return [];
    }
}
