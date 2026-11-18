<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

/** @api Public module tier contract. */
enum ModuleTier: string
{
    case System = 'system';
    case Optional = 'optional';
    case Integration = 'integration';
}
