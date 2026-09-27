<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

use DateTimeImmutable;

final readonly class ImmutableDomainEvent implements DomainEvent
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        private string $name,
        private int $version,
        private string $id,
        private DateTimeImmutable $occurredAt,
        private string $aggregateType,
        private string $aggregateId,
        private array $payload,
        private array $metadata = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{2,189}$/D', $this->name) !== 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.71a8a6e09029'));
        }
        if ($this->version < 1 || $this->version > 1000) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d7363d3e342e'));
        }
        if ($this->aggregateType === '' || strlen($this->aggregateType) > 96) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.e8a5cfe0eeda'));
        }
        if ($this->aggregateId === '' || strlen($this->aggregateId) > 190) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.184bdc9892dc'));
        }
    }

    public function eventName(): string { return $this->name; }
    public function eventVersion(): int { return $this->version; }
    public function eventId(): string { return $this->id; }
    public function occurredAt(): DateTimeImmutable { return $this->occurredAt; }
    public function aggregateType(): string { return $this->aggregateType; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function payload(): array { return $this->payload; }
    public function metadata(): array { return $this->metadata; }
}
