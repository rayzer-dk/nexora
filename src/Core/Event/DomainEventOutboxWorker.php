<?php

declare(strict_types=1);

namespace Commerce\Core\Event;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Throwable;
use Symfony\Component\Uid\Uuid;

final readonly class DomainEventOutboxWorker
{
    private const MAX_DELIVERY_ATTEMPTS = 8;
    private const LOCK_TIMEOUT_SECONDS = 300;

    public function __construct(
        private Connection $connection,
        private DomainEventSubscriberRegistry $subscribers,
    ) {
    }

    /** @return array{claimed:int,delivered:int,partial:int,dead:int} */
    public function run(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $workerId = bin2hex(random_bytes(12));
        $ids = $this->claim($workerId, $limit);
        $stats = ['claimed' => count($ids), 'delivered' => 0, 'partial' => 0, 'dead' => 0];

        foreach ($ids as $id) {
            $status = $this->processOne((int) $id, $workerId);
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $stats;
    }

    /** @return list<int> */
    private function claim(string $workerId, int $limit): array
    {
        $now = $this->now();
        $stale = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . self::LOCK_TIMEOUT_SECONDS . 'S'))
            ->format('Y-m-d H:i:s.u');

        return $this->connection->transactional(function (Connection $db) use ($workerId, $limit, $now, $stale): array {
            $ids = $db->fetchFirstColumn(
                "SELECT id FROM mc_outbox_event
                 WHERE available_at<=?
                   AND (status IN ('pending','partial') OR (status='processing' AND (locked_at IS NULL OR locked_at<?)))
                 ORDER BY id ASC
                 LIMIT {$limit}
                 FOR UPDATE",
                [$now, $stale],
            );
            if ($ids === []) {
                return [];
            }
            foreach ($ids as $id) {
                $db->update('mc_outbox_event', [
                    'status' => 'processing',
                    'locked_at' => $now,
                    'locked_by' => $workerId,
                    'attempts' => (int) $db->fetchOne('SELECT attempts FROM mc_outbox_event WHERE id=?', [(int) $id]) + 1,
                ], ['id' => (int) $id]);
            }
            return array_map('intval', $ids);
        });
    }

    private function processOne(int $id, string $workerId): string
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM mc_outbox_event WHERE id=? AND status='processing' AND locked_by=? LIMIT 1",
            [$id, $workerId],
        );
        if (!is_array($row)) {
            return 'partial';
        }

        try {
            $payload = json_decode((string) $row['payload'], true, 64, JSON_THROW_ON_ERROR);
            $metadata = json_decode((string) $row['metadata'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_array($metadata)) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.892dedd8ebdb'));
            }
            $event = new StoredDomainEvent(
                outboxId: (int) $row['id'],
                eventId: Uuid::fromBinary((string) $row['event_id'])->toRfc4122(),
                eventName: (string) $row['event_type'],
                eventVersion: (int) $row['event_version'],
                aggregateType: (string) $row['aggregate_type'],
                aggregateId: (string) $row['aggregate_id'],
                payload: $payload,
                metadata: $metadata,
                occurredAt: new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
            );
        } catch (Throwable $e) {
            $this->markEventDead($id, $this->safeMessage($e));
            return 'dead';
        }

        $subscribers = $this->subscribers->subscribersFor($event->eventName);
        if ($subscribers === []) {
            $this->completeEvent($id, 'delivered', null);
            return 'delivered';
        }

        foreach ($subscribers as $subscriber) {
            $this->ensureDelivery($id, $subscriber->subscriberId());
        }

        foreach ($subscribers as $subscriber) {
            $delivery = $this->connection->fetchAssociative(
                'SELECT * FROM mc_domain_event_delivery WHERE event_outbox_id=? AND subscriber_id=? LIMIT 1',
                [$id, $subscriber->subscriberId()],
            );
            if (!is_array($delivery) || in_array((string) $delivery['status'], ['delivered', 'dead'], true)) {
                continue;
            }
            if ($delivery['available_at'] !== null && strcmp((string) $delivery['available_at'], $this->now()) > 0) {
                continue;
            }
            try {
                $subscriber->handle($event);
                $this->connection->update('mc_domain_event_delivery', [
                    'status' => 'delivered',
                    'attempts' => (int) $delivery['attempts'] + 1,
                    'last_error' => null,
                    'delivered_at' => $this->now(),
                    'updated_at' => $this->now(),
                ], ['id' => (int) $delivery['id']]);
            } catch (Throwable $e) {
                $attempts = (int) $delivery['attempts'] + 1;
                $dead = $attempts >= self::MAX_DELIVERY_ATTEMPTS;
                $this->connection->update('mc_domain_event_delivery', [
                    'status' => $dead ? 'dead' : 'retry',
                    'attempts' => $attempts,
                    'available_at' => $dead ? null : $this->retryAt($attempts),
                    'last_error' => $this->safeMessage($e),
                    'updated_at' => $this->now(),
                ], ['id' => (int) $delivery['id']]);
            }
        }

        $pending = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM mc_domain_event_delivery WHERE event_outbox_id=? AND status NOT IN ('delivered','dead')",
            [$id],
        );
        $dead = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM mc_domain_event_delivery WHERE event_outbox_id=? AND status='dead'",
            [$id],
        );

        if ($pending > 0) {
            $this->connection->update('mc_outbox_event', [
                'status' => 'partial',
                'available_at' => $this->earliestDeliveryRetry($id),
                'locked_at' => null,
                'locked_by' => null,
                'last_error' => null,
            ], ['id' => $id]);
            return 'partial';
        }

        $this->completeEvent($id, $dead > 0 ? 'delivered_with_failures' : 'delivered', $dead > 0 ? 'One or more subscribers reached the retry limit and were isolated.' : null);
        return $dead > 0 ? 'dead' : 'delivered';
    }

    private function ensureDelivery(int $eventOutboxId, string $subscriberId): void
    {
        try {
            $now = $this->now();
            $this->connection->insert('mc_domain_event_delivery', [
                'event_outbox_id' => $eventOutboxId,
                'subscriber_id' => $subscriberId,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'delivered_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Delivery already exists; this is expected after worker retries.
        }
    }

    private function completeEvent(int $id, string $status, ?string $error): void
    {
        $now = $this->now();
        $this->connection->update('mc_outbox_event', [
            'status' => $status,
            'available_at' => $now,
            'locked_at' => null,
            'locked_by' => null,
            'last_error' => $error,
            'processed_at' => $now,
            'completed_at' => $now,
        ], ['id' => $id]);
    }

    private function markEventDead(int $id, string $error): void
    {
        $this->completeEvent($id, 'dead', $error);
    }

    private function earliestDeliveryRetry(int $eventOutboxId): string
    {
        $value = $this->connection->fetchOne(
            "SELECT MIN(available_at) FROM mc_domain_event_delivery WHERE event_outbox_id=? AND status IN ('pending','retry')",
            [$eventOutboxId],
        );
        return is_string($value) && $value !== '' ? $value : $this->retryAt(1);
    }

    private function retryAt(int $attempt): string
    {
        $seconds = min(3600, 15 * (2 ** max(0, min(8, $attempt - 1))));
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . $seconds . 'S'))
            ->format('Y-m-d H:i:s.u');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function safeMessage(Throwable $e): string
    {
        return mb_substr(preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()) ?: 'event subscriber failed', 0, 1000, 'UTF-8');
    }
}
