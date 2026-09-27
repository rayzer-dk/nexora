<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use Commerce\Modules\Shipping\Domain\DeliveryLocationResult;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Commerce\Modules\Shipping\Domain\ProviderAvailability;
use Throwable;

final readonly class DeliveryLocationGateway
{
    public function __construct(
        private DeliveryProviderRegistry $providers,
        private DeliveryProviderCircuitBreaker $circuitBreaker,
        private DeliveryCityCache $cityCache,
        private DeliveryPointCache $pointCache,
    ) {
    }

    /** @return list<DeliveryCity> */
    public function searchCities(string $providerCode, DeliveryCitySearch $search): array
    {
        $provider = $this->providers->get($providerCode);
        if (!$this->circuitBreaker->allows($providerCode)) {
            return [];
        }

        try {
            $cities = $this->cityCache->remember(
                $providerCode,
                $search,
                $provider->capabilities()->cityCacheTtlSeconds,
                static fn (): array => $provider->searchCities($search),
            );
            $this->circuitBreaker->recordSuccess($providerCode);
            return $cities;
        } catch (Throwable) {
            $this->circuitBreaker->recordFailure($providerCode);
            return [];
        }
    }

    public function searchPoints(string $providerCode, DeliveryPointSearch $search): DeliveryLocationResult
    {
        $provider = $this->providers->get($providerCode);
        $manual = $provider->capabilities()->supportsManualFallback;

        if (!$this->circuitBreaker->allows($providerCode)) {
            return new DeliveryLocationResult(
                providerCode: $providerCode,
                points: [],
                availability: ProviderAvailability::TemporarilyUnavailable,
                manualFallbackAllowed: $manual,
                notice: 'Carrier directory is temporarily unavailable. Manual delivery details may be entered.',
            );
        }

        try {
            $points = $this->pointCache->remember(
                $providerCode,
                $search,
                $provider->capabilities()->pointCacheTtlSeconds,
                static fn (): array => $provider->searchPoints($search),
            );
            $this->circuitBreaker->recordSuccess($providerCode);

            return new DeliveryLocationResult(
                providerCode: $providerCode,
                points: $points,
                availability: ProviderAvailability::Available,
                manualFallbackAllowed: $manual,
            );
        } catch (Throwable) {
            $this->circuitBreaker->recordFailure($providerCode);

            return new DeliveryLocationResult(
                providerCode: $providerCode,
                points: [],
                availability: ProviderAvailability::TemporarilyUnavailable,
                manualFallbackAllowed: $manual,
                notice: 'Carrier API did not respond. The order can still be placed using manual delivery details.',
            );
        }
    }
}
