<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

enum DeliveryServiceType: string
{
    case PickupPoint = 'pickup_point';
    case ParcelLocker = 'parcel_locker';
    case Courier = 'courier';
    case StorePickup = 'store_pickup';
    case Freight = 'freight';
}
