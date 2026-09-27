<?php

declare(strict_types=1);

namespace Commerce\Modules\Inventory\Domain;

use InvalidArgumentException;

/**
 * Quantities use six-decimal fixed precision expressed as integer micros.
 * Persistence may use DECIMAL(18,6); repositories convert exactly at the boundary.
 */
final readonly class InventoryAvailability
{
    public const SCALE = 1_000_000;

    public function __construct(
        public int $stockedMicros,
        public int $reservedMicros,
        public int $safetyStockMicros = 0,
        public bool $allowBackorder = false,
    ) {
        if ($reservedMicros < 0 || $safetyStockMicros < 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0fe01203e73b'));
        }
    }

    public function availableMicros(): int
    {
        return max(0, $this->stockedMicros - $this->reservedMicros - $this->safetyStockMicros);
    }

    public function canFulfillMicros(int $quantityMicros): bool
    {
        if ($quantityMicros <= 0) {
            return false;
        }

        return $this->allowBackorder || $this->availableMicros() >= $quantityMicros;
    }
}
