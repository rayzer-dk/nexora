<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Domain;

enum ProductFulfillmentType: string
{
    case Physical = 'physical';
    case Digital = 'digital';
    case Service = 'service';
}
