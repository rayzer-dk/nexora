<?php

declare(strict_types=1);

namespace Commerce\Modules\Inventory\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReservationRequest
{
    public function __construct(
        public int $inventoryItemId,
        public int $locationId,
        public int $quantityMicros,
        public string $idempotencyKey,
        public ?int $cartId = null,
        public ?int $orderId = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if ($inventoryItemId < 1 || $locationId < 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6df5ad250f64'));
        }
        if ($quantityMicros <= 0) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2b4bca718666'));
        }
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f7fafac910d7'));
        }
        if ($cartId === null && $orderId === null) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.84c5aa9ffc20'));
        }
    }
}
