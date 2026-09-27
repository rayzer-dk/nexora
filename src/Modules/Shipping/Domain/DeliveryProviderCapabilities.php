<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

final readonly class DeliveryProviderCapabilities
{
    /**
     * @param list<DeliveryServiceType> $serviceTypes
     */
    public function __construct(
        public array $serviceTypes,
        public LocationCatalogMode $locationCatalogMode = LocationCatalogMode::LiveQuery,
        public bool $supportsLiveRates = false,
        public bool $supportsShipmentCreation = false,
        public bool $supportsTracking = false,
        public bool $supportsReturns = false,
        public bool $supportsWebhooks = false,
        public bool $supportsManualFallback = true,
        public int $cityCacheTtlSeconds = 86400,
        public int $pointCacheTtlSeconds = 300,
    ) {
    }

    public function supports(DeliveryServiceType $type): bool
    {
        return in_array($type, $this->serviceTypes, true);
    }

    public function persistsFullPointDirectory(): bool
    {
        return $this->locationCatalogMode === LocationCatalogMode::SyncedDirectory;
    }
}
