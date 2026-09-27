<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

use InvalidArgumentException;

final readonly class DeliveryCitySearch
{
    public function __construct(
        public string $countryCode,
        public string $query,
        public int $limit = 20,
    ) {
        if (!preg_match('/^[A-Z]{2}$/', $this->countryCode)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.446babccb27a'));
        }
        if (mb_strlen(trim($this->query)) < 2) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.64507f009764'));
        }
        if ($this->limit < 1 || $this->limit > 50) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c112d9f10beb'));
        }
    }
}
