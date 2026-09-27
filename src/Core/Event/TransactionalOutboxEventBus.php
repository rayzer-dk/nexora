<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

use Commerce\Core\Runtime\DeferredWorkSignal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Persists events through the same Doctrine DBAL connection used by the current
 * business transaction. No listener is executed inline with checkout/payment.
 */
final readonly class TransactionalOutboxEventBus implements EventBusInterface
{
    private const MAX_JSON_BYTES = 262144;

    public function __construct(
        private Connection $connection,
        private DeferredWorkSignal $deferredWork,
    ) {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $payload = $this->encode($event->payload(), 'payload');
            $metadata = $this->encode($event->metadata(), 'metadata');
            $occurredAt = $event->occurredAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

            try {
                $this->connection->insert('mc_outbox_event', [
                    'event_id' => Uuid::fromString($event->eventId())->toBinary(),
                    'event_type' => $event->eventName(),
                    'event_version' => $event->eventVersion(),
                    'aggregate_type' => $event->aggregateType(),
                    'aggregate_id' => $event->aggregateId(),
                    'payload' => $payload,
                    'metadata' => $metadata,
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => $now,
                    'locked_at' => null,
                    'locked_by' => null,
                    'last_error' => null,
                    'occurred_at' => $occurredAt,
                    'created_at' => $now,
                    'completed_at' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Publishing the same immutable event twice is idempotent. A unique event_id
                // prevents duplicate asynchronous side effects after command retries.
            }
            $this->deferredWork->mark();
        }
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value, string $label): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.31f313a425b7') . $label . \Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.99cb4f2a1c97'));
        }
        return $json;
    }
}
