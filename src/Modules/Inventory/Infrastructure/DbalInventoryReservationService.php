<?php

declare(strict_types=1);

namespace Commerce\Modules\Inventory\Infrastructure;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Inventory\Contract\InventoryReservationServiceInterface;
use Commerce\Modules\Inventory\Domain\InventoryAvailability;
use Commerce\Modules\Inventory\Domain\ReservationRequest;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Transactional reservation service for MySQL/MariaDB InnoDB.
 * Availability is claimed with one conditional UPDATE, so concurrent checkouts cannot oversell the same stock row.
 */
final readonly class DbalInventoryReservationService implements InventoryReservationServiceInterface
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
    ) {
    }

    public function reserve(ReservationRequest $request): string
    {
        return $this->connection->transactional(function (Connection $connection) use ($request): string {
            $existing = $connection->fetchAssociative(
                'SELECT public_id, quantity, status FROM mc_inventory_reservation'
                . ' WHERE inventory_item_id = ? AND location_id = ? AND idempotency_key = ? LIMIT 1',
                [$request->inventoryItemId, $request->locationId, $request->idempotencyKey],
            );

            if (is_array($existing)) {
                if ($this->decimalToMicros((string) $existing['quantity']) !== $request->quantityMicros) {
                    throw new InvalidReservationState(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7b9cb6486029'));
                }

                return Uuid::fromBinary((string) $existing['public_id'])->toRfc4122();
            }

            $quantity = $this->microsToDecimal($request->quantityMicros);
            $now = $this->now();
            $claimed = $connection->executeStatement(
                'UPDATE mc_stock_level'
                . ' SET reserved_quantity = reserved_quantity + ?, row_version = row_version + 1, updated_at = ?'
                . ' WHERE inventory_item_id = ? AND location_id = ?'
                . ' AND (stocked_quantity - reserved_quantity - safety_stock) >= ?',
                [$quantity, $now, $request->inventoryItemId, $request->locationId, $quantity],
            );

            if ($claimed !== 1) {
                throw new InsufficientInventory(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.16857c9bbbf6'));
            }

            $publicId = $this->publicIds->generate();
            $connection->insert('mc_inventory_reservation', [
                'public_id' => $publicId->toBinary(),
                'inventory_item_id' => $request->inventoryItemId,
                'location_id' => $request->locationId,
                'cart_id' => $request->cartId,
                'order_id' => $request->orderId,
                'idempotency_key' => $request->idempotencyKey,
                'quantity' => $quantity,
                'status' => 'active',
                'expires_at' => $request->expiresAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'created_at' => $now,
                'released_at' => null,
                'committed_at' => null,
            ]);

            return $publicId->toRfc4122();
        });
    }

    public function release(string $reservationPublicId): void
    {
        $this->transition($reservationPublicId, 'released');
    }

    public function commit(string $reservationPublicId): void
    {
        $this->transition($reservationPublicId, 'committed');
    }

    private function transition(string $reservationPublicId, string $target): void
    {
        $binaryId = Uuid::fromString($reservationPublicId)->toBinary();

        $this->connection->transactional(function (Connection $connection) use ($binaryId, $target): void {
            $reservation = $connection->fetchAssociative(
                'SELECT id, inventory_item_id, location_id, quantity, status'
                . ' FROM mc_inventory_reservation WHERE public_id = ? FOR UPDATE',
                [$binaryId],
            );

            if (!is_array($reservation)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7cc44b767221'));
            }

            $status = (string) $reservation['status'];
            if ($status === $target) {
                return;
            }
            if ($status !== 'active') {
                throw new InvalidReservationState(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.5e5ab2f515bf'), $status, $target));
            }

            $quantity = (string) $reservation['quantity'];
            $now = $this->now();
            if ($target === 'released') {
                $changed = $connection->executeStatement(
                    'UPDATE mc_stock_level'
                    . ' SET reserved_quantity = reserved_quantity - ?, row_version = row_version + 1, updated_at = ?'
                    . ' WHERE inventory_item_id = ? AND location_id = ? AND reserved_quantity >= ?',
                    [$quantity, $now, $reservation['inventory_item_id'], $reservation['location_id'], $quantity],
                );
            } else {
                $changed = $connection->executeStatement(
                    'UPDATE mc_stock_level'
                    . ' SET stocked_quantity = stocked_quantity - ?, reserved_quantity = reserved_quantity - ?,'
                    . ' row_version = row_version + 1, updated_at = ?'
                    . ' WHERE inventory_item_id = ? AND location_id = ?'
                    . ' AND stocked_quantity >= ? AND reserved_quantity >= ?',
                    [$quantity, $quantity, $now, $reservation['inventory_item_id'], $reservation['location_id'], $quantity, $quantity],
                );
            }

            if ($changed !== 1) {
                throw new InvalidReservationState(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ff1a9d6ed0be'));
            }

            $connection->update('mc_inventory_reservation', [
                'status' => $target,
                $target === 'released' ? 'released_at' : 'committed_at' => $now,
            ], ['id' => $reservation['id']]);
        });
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function microsToDecimal(int $micros): string
    {
        $whole = intdiv($micros, InventoryAvailability::SCALE);
        $fraction = $micros % InventoryAvailability::SCALE;

        return sprintf('%d.%06d', $whole, $fraction);
    }

    private function decimalToMicros(string $decimal): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $decimal, $match) !== 1) {
            throw new InvalidReservationState(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f98570f8c9a0'));
        }

        $fraction = str_pad($match[2] ?? '', 6, '0');

        return ((int) $match[1] * InventoryAvailability::SCALE) + (int) $fraction;
    }
}
