<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\System;

final class InformationPageReadinessChecker
{
    /**
     * @param array<string,mixed> $storeProfile
     * @return list<string>
     */
    public function missingFields(InformationPageDefinition $page, array $storeProfile): array
    {
        $missing = [];
        foreach ($page->requiredStoreFields as $field) {
            $value = $storeProfile[$field] ?? null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /** @param array<string,mixed> $storeProfile */
    public function isReady(InformationPageDefinition $page, array $storeProfile): bool
    {
        return $this->missingFields($page, $storeProfile) === [];
    }
}
