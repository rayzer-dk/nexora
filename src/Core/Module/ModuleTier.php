<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

enum ModuleTier: string
{
    case System = 'system';
    case Optional = 'optional';
    case Integration = 'integration';
}
