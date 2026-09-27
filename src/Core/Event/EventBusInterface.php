<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

interface EventBusInterface
{
    /**
     * Persists immutable events for asynchronous delivery. Call this inside the
     * same DB transaction as the state change whenever atomicity is required.
     */
    public function publish(DomainEvent ...$events): void;
}
