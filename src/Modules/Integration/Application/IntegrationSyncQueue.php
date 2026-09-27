<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class IntegrationSyncQueue
{
    public function __construct(private Connection $db)
    {
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(string $integration, string $aggregateType, string $aggregateId, string $operation, array $payload, string $dedupeKey): void
    {
        try {
            $this->db->insert('mc_integration_sync_queue', [
                'integration_code' => $integration,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'operation' => $operation,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'dedupe_key' => $dedupeKey,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => null,
                'last_error' => null,
                'created_at' => gmdate('Y-m-d H:i:s.u'),
                'updated_at' => gmdate('Y-m-d H:i:s.u'),
                'completed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Idempotent enqueue. Existing work item remains authoritative.
        }
    }
}
