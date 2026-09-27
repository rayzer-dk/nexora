<?php

declare(strict_types=1);

namespace Commerce\Modules\ConsumerRights\Pricing;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class LowestPriorPriceCalculator
{
    /** @param list<PriceHistoryPoint> $history */
    public function lowest(array $history, DateTimeImmutable $reductionStartsAt, int $lookbackDays = 30): ?int
    {
        if ($lookbackDays < 1 || $lookbackDays > 366) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.54103490b934'));
        }

        $windowStart = $reductionStartsAt->sub(new DateInterval('P' . $lookbackDays . 'D'));
        $lowest = null;

        foreach ($history as $point) {
            if (!$point instanceof PriceHistoryPoint) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.bbcf4f6858b6'));
            }

            $pointEnd = $point->validTo ?? $reductionStartsAt;
            $overlapsWindow = $point->validFrom < $reductionStartsAt && $pointEnd > $windowStart;
            if (!$overlapsWindow) {
                continue;
            }

            $lowest = $lowest === null ? $point->amountMinor : min($lowest, $point->amountMinor);
        }

        return $lowest;
    }
}
