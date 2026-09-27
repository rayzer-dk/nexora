<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

interface DomainEventSubscriberInterface
{
    /** Stable identifier used for idempotent delivery bookkeeping. */
    public function subscriberId(): string;

    /** @return list<string> */
    public function subscribedEvents(): array;

    public function handle(StoredDomainEvent $event): void;
}
