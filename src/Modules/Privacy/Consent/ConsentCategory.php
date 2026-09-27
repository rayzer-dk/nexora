<?php

declare(strict_types=1);

namespace Commerce\Modules\Privacy\Consent;

enum ConsentCategory: string
{
    case Necessary = 'necessary';
    case Preferences = 'preferences';
    case Analytics = 'analytics';
    case Marketing = 'marketing';
}
