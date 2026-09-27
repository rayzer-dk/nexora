<?php

declare(strict_types=1);

namespace Commerce\Modules\Pricing\Domain;

use Commerce\Modules\Catalog\Measurement\Quantity;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PriceContext
{
    /** @param array<string, scalar|list<scalar>> $attributes */
    public function __construct(
        public int $storeId,
        public ?int $marketId,
        public string $currency,
        public int $quantityMicros = Quantity::FACTOR,
        public ?string $customerGroup = null,
        public array $attributes = [],
        public ?DateTimeImmutable $at = null,
    ) {
        if ($storeId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5055bd4bc946'));
        }
        if ($marketId !== null && $marketId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.701860dbb300'));
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.30d8b1848c4f'));
        }
        if ($quantityMicros < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7cc371ad5fec'));
        }
    }

    public function quantity(): Quantity
    {
        return Quantity::fromMicros($this->quantityMicros);
    }
}
