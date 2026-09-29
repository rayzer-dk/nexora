<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Modules\Integration\Application\IntegrationSyncQueue;

final readonly class CatalogGoogleSyncSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(
        private IntegrationSyncQueue $queue,
        private bool $enabled,
    ) {}

    public function subscriberId(): string { return 'google_commerce.catalog_sync.v1'; }

    public function subscribedEvents(): array
    {
        return [EventNames::PRODUCT_CREATED, EventNames::PRODUCT_UPDATED];
    }

    public function handle(StoredDomainEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }
        $productId = (string) ($event->payload['product_id'] ?? $event->aggregateId ?? '');
        if ($productId === '') {
            return;
        }
        $storeId = isset($event->payload['store_id']) ? (int) $event->payload['store_id'] : null;
        $this->queue->enqueue('google_merchant', 'product', $productId, 'upsert', [
            'event_id' => $event->eventId,
            'event_version' => $event->eventVersion,
            'store_id' => $storeId,
            'market_id' => isset($event->payload['market_id']) ? (int) $event->payload['market_id'] : null,
        ], 'google:product:' . $productId . ':' . $event->eventId, $storeId);
    }
}
