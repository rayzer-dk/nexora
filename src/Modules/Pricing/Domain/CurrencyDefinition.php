<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Domain;

use InvalidArgumentException;

final readonly class CurrencyDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public int $minorUnits = 2,
        public ?string $symbol = null,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.dd8af7c09999'));
        }
        if ($minorUnits < 0 || $minorUnits > 4) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c865f305bdb8'));
        }
    }
}
