<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Measurement;

use InvalidArgumentException;

final readonly class SaleQuantityPolicy
{
    private int $stepMicros;
    private int $minimumMicros;
    private ?int $maximumMicros;

    public function __construct(
        public string $unitCode = 'item',
        public string $step = '1.000000',
        public string $minimum = '1.000000',
        public ?string $maximum = null,
    ) {
        $this->stepMicros = Quantity::fromString($step)->micros;
        $this->minimumMicros = Quantity::fromString($minimum)->micros;
        $this->maximumMicros = $maximum !== null ? Quantity::fromString($maximum)->micros : null;

        if ($this->stepMicros <= 0 || $this->minimumMicros <= 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.28f784009274'));
        }
        if ($this->maximumMicros !== null && $this->maximumMicros < $this->minimumMicros) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6d833ea305d6'));
        }
    }

    public function accepts(string $quantity): bool
    {
        $q = Quantity::fromString($quantity)->micros;
        if ($q < $this->minimumMicros || ($this->maximumMicros !== null && $q > $this->maximumMicros)) {
            return false;
        }

        return (($q - $this->minimumMicros) % $this->stepMicros) === 0;
    }
}
