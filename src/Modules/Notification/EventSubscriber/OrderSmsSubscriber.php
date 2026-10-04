<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Modules\Notification\Application\SmsService;

/** Automatic SMS to the customer when an order is placed or cancelled (shipping and pickup SMS follow the delivery status, see OrderNotificationService). */
final readonly class OrderSmsSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(private SmsService $sms)
    {
    }

    public function subscriberId(): string
    {
        return 'core.notification.order_sms.v1';
    }

    public function subscribedEvents(): array
    {
        return [EventNames::ORDER_PLACED, EventNames::ORDER_CANCELLED];
    }

    public function handle(StoredDomainEvent $event): void
    {
        $this->sms->sendAutoByPublicId($event->aggregateId, $event->eventName === EventNames::ORDER_CANCELLED ? 'cancelled' : 'placed');
    }
}
