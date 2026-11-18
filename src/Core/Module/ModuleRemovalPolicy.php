<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

/** @api Public removal-policy vocabulary for modules. */
enum ModuleRemovalPolicy: string
{
    case Protected = 'protected';
    case DisableOnly = 'disable_only';
    case Removable = 'removable';
}
