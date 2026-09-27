<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Contract;

use Commerce\Modules\Shipping\Domain\DeliveryCity;
use Commerce\Modules\Shipping\Domain\DeliveryCitySearch;
use Commerce\Modules\Shipping\Domain\DeliveryPoint;
use Commerce\Modules\Shipping\Domain\DeliveryPointSearch;
use Commerce\Modules\Shipping\Domain\DeliveryProviderCapabilities;
use Commerce\Modules\Shipping\Domain\DeliveryQuote;
use Commerce\Modules\Shipping\Domain\DeliveryQuoteRequest;

interface DeliveryProviderInterface
{
    public function code(): string;

    public function label(): string;

    public function capabilities(): DeliveryProviderCapabilities;

    /** @return list<DeliveryCity> */
    public function searchCities(DeliveryCitySearch $search): array;

    /** @return list<DeliveryPoint> */
    public function searchPoints(DeliveryPointSearch $search): array;

    /** @return list<DeliveryQuote> */
    public function quote(DeliveryQuoteRequest $request): array;
}
