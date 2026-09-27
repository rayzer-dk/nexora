<?php

declare(strict_types=1);

namespace Commerce\Modules\ConsumerRights\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PriceHistoryPoint
{
    public function __construct(
        public int $amountMinor,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo = null,
    ) {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cbd214190a68'));
        }
        if ($validTo !== null && $validTo <= $validFrom) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.73b01420c56e'));
        }
    }
}
