<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

enum DeliveryPointType: string
{
    case Branch = 'branch';
    case ParcelLocker = 'parcel_locker';
    case ServicePoint = 'service_point';
    case Store = 'store';
}
