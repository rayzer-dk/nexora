<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Modules\Integration\Application\IntegrationSyncQueue;

final readonly class CommerceMarketingSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(
        private IntegrationSyncQueue $queue,
        private bool $ga4Enabled,
        private bool $metaEnabled,
        private bool $tiktokEnabled,
    ) {}

    public function subscriberId(): string { return 'marketing.commerce_events.v1'; }

    public function subscribedEvents(): array
    {
        return [EventNames::ORDER_PLACED, EventNames::ORDER_COMPLETED, EventNames::CUSTOMER_REGISTERED];
    }

    public function handle(StoredDomainEvent $event): void
    {
        if (!$this->ga4Enabled && !$this->metaEnabled && !$this->tiktokEnabled) {
            return;
        }
        $this->queue->enqueue('marketing', $event->aggregateType, $event->aggregateId, $event->eventName, [
            'event_id' => $event->eventId,
            'event_name' => $event->eventName,
            'event_version' => $event->eventVersion,
            'payload' => $event->payload,
            'consent' => is_array($event->payload['consent'] ?? null) ? $event->payload['consent'] : null,
        ], 'marketing:' . $event->eventId);
    }
}
