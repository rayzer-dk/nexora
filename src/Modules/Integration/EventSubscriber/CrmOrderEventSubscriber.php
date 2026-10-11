<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Modules\Integration\Application\IntegrationSyncQueue;
use Commerce\Modules\Integration\Crm\CrmConnectionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** An order event wakes the connections that listen to it: one queue job per connection, so a slow receiver never holds the others back. */
final readonly class CrmOrderEventSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(private CrmConnectionRepository $connections, private IntegrationSyncQueue $queue, private Connection $db)
    {
    }

    public function subscriberId(): string
    {
        return 'integration.crm_orders.v1';
    }

    public function subscribedEvents(): array
    {
        return [EventNames::ORDER_PLACED, EventNames::ORDER_COMPLETED, EventNames::ORDER_CANCELLED, EventNames::ORDER_SHIPPED, EventNames::PAYMENT_STATUS_CHANGED];
    }

    public function handle(StoredDomainEvent $event): void
    {
        $label = match ($event->eventName) {
            EventNames::ORDER_PLACED => 'placed',
            EventNames::ORDER_COMPLETED => 'completed',
            EventNames::ORDER_CANCELLED => 'cancelled',
            EventNames::ORDER_SHIPPED => 'shipped',
            EventNames::PAYMENT_STATUS_CHANGED => ($event->payload['status'] ?? '') === 'paid' ? 'paid' : null,
            default => null,
        };
        if ($label === null || $event->aggregateType !== 'order') {
            return;
        }
        $storeId = isset($event->payload['store_id']) ? (int) $event->payload['store_id'] : (int) $this->db->fetchOne('SELECT store_id FROM mc_sales_order WHERE public_id=?', [Uuid::fromString($event->aggregateId)->toBinary()]);
        if ($storeId < 1) {
            return;
        }
        foreach ($this->connections->forEvent($storeId, $label) as $connection) {
            $this->queue->enqueue('crm', 'order', $event->aggregateId, $event->eventName, [
                'connection_id' => $connection['id'], 'order' => $event->aggregateId, 'event' => $label,
            ], 'crm:' . $connection['id'] . ':' . $event->eventId, $storeId);
        }
    }
}
