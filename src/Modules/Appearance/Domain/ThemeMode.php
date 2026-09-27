<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Domain;

enum ThemeMode: string
{
    case Light = 'light';
    case Dark = 'dark';
    case Auto = 'auto';
}
