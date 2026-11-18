<?php

declare(strict_types=1);

namespace Commerce\Core\Region;

/** @api Public identifiers for regional modules. */
enum CommerceRegion: string
{
    case Europe = 'EU';
    case Ukraine = 'UA';
    case EuropeUkraine = 'EU_UA';
}
