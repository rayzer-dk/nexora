<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

use InvalidArgumentException;

final readonly class DeliveryPointSearch
{
    /** @param list<DeliveryPointType> $types */
    public function __construct(
        public string $countryCode,
        public ?string $query = null,
        public ?string $cityId = null,
        public ?string $cityName = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public array $types = [],
        public int $limit = 20,
    ) {
        if (!preg_match('/^[A-Z]{2}$/', $this->countryCode)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.deb66fd61f28'));
        }
        if ($this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c09cb4da27b6'));
        }
        if (($this->latitude === null) !== ($this->longitude === null)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0b2aacd0d6c9'));
        }
    }
}
