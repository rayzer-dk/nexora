<?php

declare(strict_types=1);

namespace Commerce\Modules\Inventory\Contract;

use Commerce\Modules\Inventory\Domain\ReservationRequest;

interface InventoryReservationServiceInterface
{
    public function reserve(ReservationRequest $request): string;

    public function release(string $reservationPublicId): void;

    public function commit(string $reservationPublicId): void;
}
