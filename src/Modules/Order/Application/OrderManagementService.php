<?php

declare(strict_types=1);

namespace Commerce\Modules\Order\Application;

use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Administrative order mutations with explicit invariants.
 *
 * Storefront/order history must remain usable even when an optional delivery or
 * notification integration is unavailable. This service only persists local,
 * validated state and never invokes external providers.
 */
final readonly class OrderManagementService
{
    private const FULFILLMENT_TRANSITIONS = [
        'pending' => ['preparing', 'ready_for_pickup', 'shipped', 'cancelled'],
        'unfulfilled' => ['preparing', 'ready_for_pickup', 'shipped', 'cancelled'],
        'preparing' => ['ready_for_pickup', 'shipped', 'cancelled'],
        'ready_for_pickup' => ['shipped', 'delivered', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private Connection $db,
        private EventBusInterface $events,
        private DomainEventFactory $eventFactory,
    ) {}

    public function addInternalNote(string $orderPublicId, string $note, string $actor): void
    {
        $note = trim($note);
        if ($note === '') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.vvedit_tekst_notatky'));
        }
        if (mb_strlen($note) > 4000) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.notatka_zanadto_dovha_maksymum_4000_symvoliv'));
        }

        $this->db->transactional(function (Connection $db) use ($orderPublicId, $note, $actor): void {
            $order = $this->lockOrder($db, $orderPublicId);
            $this->appendEvent($db, (int) $order['id'], 'admin.note', ['note' => $note], 'admin', $actor);
        });
    }

    public function updateFulfillment(string $orderPublicId, string $status, ?string $trackingNumber, string $actor): void
    {
        $status = trim($status);
        $trackingNumber = trim((string) $trackingNumber);
        if (!in_array($status, ['pending', 'preparing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled'], true)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.nepidtrymuvanyi_status_dostavky'));
        }
        if (mb_strlen($trackingNumber) > 190) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.nomer_vidpravlennia_zanadto_dovhyi'));
        }

        $this->db->transactional(function (Connection $db) use ($orderPublicId, $status, $trackingNumber, $actor): void {
            $order = $this->lockOrder($db, $orderPublicId);
            if (in_array((string) $order['status'], ['cancelled', 'refunded'], true)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.dlia_skasovanoho_abo_povnistiu_povernenoho_zamovlenn'));
            }

            $fulfillment = $db->fetchAssociative(
                'SELECT * FROM mc_fulfillment WHERE order_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [(int) $order['id']],
            );
            if (!is_array($fulfillment)) {
                if ((string) $order['fulfillment_status'] === 'not_required') {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.tsyfrove_zamovlennia_ne_potrebuie_dostavky'));
                }
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zapys_dostavky_dlia_zamovlennia_ne_znaideno'));
            }

            $current = (string) $fulfillment['status'];
            if ($current !== $status) {
                $allowed = self::FULFILLMENT_TRANSITIONS[$current] ?? [];
                if (!in_array($status, $allowed, true)) {
                    throw new \DomainException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.perekhid_dostavky_s_s_zaboronenyi'), $current, $status));
                }
            }

            // A shipment needs a tracking number, but it may already be stored; later steps (delivered, pickup) keep it and never require one.
            $trackingNumber = $trackingNumber !== '' ? $trackingNumber : trim((string) ($fulfillment['tracking_number'] ?? ''));
            if ($status === 'shipped' && $trackingNumber === '') {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.dlia_vidpravlenoho_zamovlennia_vkazhit_nomer_vidprav'));
            }

            $now = $this->now();
            $db->update('mc_fulfillment', [
                'status' => $status,
                'tracking_number' => $trackingNumber !== '' ? $trackingNumber : null,
                'updated_at' => $now,
            ], ['id' => (int) $fulfillment['id']]);

            $orderStatus = (string) $order['status'];
            if (in_array($status, ['preparing', 'ready_for_pickup', 'shipped'], true)
                && in_array($orderStatus, ['placed', 'confirmed'], true)) {
                $orderStatus = 'processing';
            }
            if ($status === 'delivered' && (string) $order['payment_status'] === 'paid') {
                $orderStatus = 'completed';
            }

            $db->update('mc_sales_order', [
                'status' => $orderStatus,
                'fulfillment_status' => $status,
                'updated_at' => $now,
            ], ['id' => (int) $order['id']]);

            $this->appendEvent($db, (int) $order['id'], 'fulfillment.status_changed', [
                'from' => $current,
                'to' => $status,
                'tracking_number' => $trackingNumber !== '' ? $trackingNumber : null,
            ], 'admin', $actor);
        });
    }

    public function markCompleted(string $orderPublicId, string $actor): void
    {
        $this->db->transactional(function (Connection $db) use ($orderPublicId, $actor): void {
            $order = $this->lockOrder($db, $orderPublicId);
            if ((string) $order['status'] === 'completed') {
                return;
            }
            if (in_array((string) $order['status'], ['cancelled', 'refunded', 'payment_failed'], true)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.tse_zamovlennia_ne_mozhna_zavershyty'));
            }
            if ((string) $order['payment_status'] !== 'paid') {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.pered_zavershenniam_zamovlennia_pidtverdte_oplatu'));
            }
            if (!in_array((string) $order['fulfillment_status'], ['delivered', 'not_required'], true)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.pered_zavershenniam_zamovlennia_pidtverdte_dostavku'));
            }

            $db->update('mc_sales_order', [
                'status' => 'completed',
                'updated_at' => $this->now(),
            ], ['id' => (int) $order['id']]);
            $this->appendEvent($db, (int) $order['id'], 'order.completed', [], 'admin', $actor);
            $this->events->publish($this->eventFactory->create(EventNames::ORDER_COMPLETED, 'order', $orderPublicId, [], ['actor' => 'admin']));
        });
    }

    /** @return array<string,mixed> */
    private function lockOrder(Connection $db, string $publicId): array
    {
        try {
            $binary = Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.nekorektnyi_identyfikator_zamovlennia'));
        }
        $order = $db->fetchAssociative('SELECT * FROM mc_sales_order WHERE public_id=? FOR UPDATE', [$binary]);
        if (!is_array($order)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zamovlennia_ne_znaideno'));
        }
        return $order;
    }

    /** @param array<string,mixed> $payload */
    private function appendEvent(Connection $db, int $orderId, string $type, array $payload, string $actorType, string $actor): void
    {
        $next = (int) $db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM mc_order_event WHERE order_id=?', [$orderId]);
        $db->insert('mc_order_event', [
            'order_id' => $orderId,
            'sequence_no' => $next,
            'event_type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor_type' => $actorType,
            'actor_subject' => $actor,
            'created_at' => $this->now(),
        ]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
