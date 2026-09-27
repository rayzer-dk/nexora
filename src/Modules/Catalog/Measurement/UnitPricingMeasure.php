<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Measurement;

final readonly class UnitPricingMeasure
{
    public function __construct(
        public string $value,
        public string $unitCode,
        public ?string $baseValue = null,
        public ?string $baseUnitCode = null,
    ) {
        if ((float) $value <= 0) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bb9892d7a6e5'));
        }
    }
}
