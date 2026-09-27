<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Measurement;

enum MeasurementDimension: string
{
    case Count = 'count';
    case Mass = 'mass';
    case Volume = 'volume';
    case Length = 'length';
    case Area = 'area';
    case Time = 'time';
}
