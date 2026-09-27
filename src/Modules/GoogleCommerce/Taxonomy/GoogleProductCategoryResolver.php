<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Taxonomy;

final class GoogleProductCategoryResolver
{
    public function resolve(?string $productOverride, ?string $primaryCategoryDefault): GoogleProductCategoryResolution
    {
        $productOverride = $this->clean($productOverride);
        if ($productOverride !== null) {
            return new GoogleProductCategoryResolution($productOverride, 'product_override');
        }

        $primaryCategoryDefault = $this->clean($primaryCategoryDefault);
        if ($primaryCategoryDefault !== null) {
            return new GoogleProductCategoryResolution($primaryCategoryDefault, 'primary_category_default');
        }

        return new GoogleProductCategoryResolution(null, 'google_auto_classification');
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
