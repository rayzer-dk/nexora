<?php

declare(strict_types=1);

namespace Commerce\Core\Module;

enum ModuleRemovalPolicy: string
{
    case Protected = 'protected';
    case DisableOnly = 'disable_only';
    case Removable = 'removable';
}
