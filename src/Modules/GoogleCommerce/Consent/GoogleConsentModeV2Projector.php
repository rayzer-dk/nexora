<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Consent;

use Commerce\Modules\Privacy\Consent\ConsentSelection;

final class GoogleConsentModeV2Projector
{
    /** @return array<string, 'granted'|'denied'> */
    public function project(ConsentSelection $selection): array
    {
        return [
            'security_storage' => 'granted',
            'functionality_storage' => $selection->preferences ? 'granted' : 'denied',
            'personalization_storage' => $selection->preferences ? 'granted' : 'denied',
            'analytics_storage' => $selection->analytics ? 'granted' : 'denied',
            'ad_storage' => $selection->marketing ? 'granted' : 'denied',
            'ad_user_data' => $selection->marketing ? 'granted' : 'denied',
            'ad_personalization' => $selection->marketing ? 'granted' : 'denied',
        ];
    }
}
