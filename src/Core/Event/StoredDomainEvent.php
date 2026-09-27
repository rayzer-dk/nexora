<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

use DateTimeImmutable;

final readonly class StoredDomainEvent
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public int $outboxId,
        public string $eventId,
        public string $eventName,
        public int $eventVersion,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public array $metadata,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
