<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Application;

final class MarketingConsentGate
{
    /** @param array<string,mixed> $event */
    public function allows(array $event, string $scope): bool
    {
        $consent = $event['payload']['consent'] ?? $event['consent'] ?? null;
        if (!is_array($consent)) {
            return false;
        }
        return match ($scope) {
            'analytics' => ($consent['analytics'] ?? false) === true || ($consent['analytics_storage'] ?? false) === true,
            'marketing' => ($consent['marketing'] ?? false) === true || (($consent['ad_storage'] ?? false) === true && ($consent['ad_user_data'] ?? false) === true),
            default => false,
        };
    }
}
