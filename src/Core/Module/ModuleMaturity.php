<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

/** @api Public metadata vocabulary for modules. */
enum ModuleMaturity: string
{
    case Stable = 'stable';
    case Beta = 'beta';
    case Foundation = 'foundation';
    case Planned = 'planned';
}
