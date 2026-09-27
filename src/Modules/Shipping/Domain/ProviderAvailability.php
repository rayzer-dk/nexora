<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

enum ProviderAvailability: string
{
    case Available = 'available';
    case Degraded = 'degraded';
    case TemporarilyUnavailable = 'temporarily_unavailable';
}
