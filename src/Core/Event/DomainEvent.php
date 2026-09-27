<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

use DateTimeImmutable;

interface DomainEvent
{
    public function eventName(): string;

    public function eventVersion(): int;

    public function eventId(): string;

    public function occurredAt(): DateTimeImmutable;

    public function aggregateType(): string;

    public function aggregateId(): string;

    /** @return array<string,mixed> */
    public function payload(): array;

    /** @return array<string,mixed> */
    public function metadata(): array;
}
