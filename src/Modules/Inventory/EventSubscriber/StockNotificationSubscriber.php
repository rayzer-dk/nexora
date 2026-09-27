<?php

declare(strict_types=1);

namespace Commerce\Modules\Inventory\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Modules\Inventory\Application\StockNotificationService;

final readonly class StockNotificationSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(private StockNotificationService $notifications) {}
    public function subscriberId(): string { return 'inventory.stock_notification.v1'; }
    public function subscribedEvents(): array { return [EventNames::PRODUCT_UPDATED]; }
    public function handle(StoredDomainEvent $event): void { $this->notifications->notifyAvailableProduct($event->aggregateId); }
}
