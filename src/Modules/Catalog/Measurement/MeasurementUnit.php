<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Measurement;

final readonly class MeasurementUnit
{
    public function __construct(
        public string $code,
        public MeasurementDimension $dimension,
        public string $symbol,
        public ?string $uneceCode = null,
        public ?string $googleUnitCode = null,
        public int $decimalScale = 0,
    ) {
        if ($code === '' || $decimalScale < 0 || $decimalScale > 6) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3a9a5119ef16'));
        }
    }
}
