<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;

final readonly class DomainEventFactory
{
    public function __construct(private PublicIdFactory $ids)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    public function create(
        string $name,
        string $aggregateType,
        string $aggregateId,
        array $payload = [],
        array $metadata = [],
        int $version = 1,
    ): DomainEvent {
        return new ImmutableDomainEvent(
            name: $name,
            version: $version,
            id: $this->ids->rfc4122(),
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
            metadata: $metadata,
        );
    }
}
