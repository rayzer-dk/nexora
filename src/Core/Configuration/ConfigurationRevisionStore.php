<?php

declare(strict_types=1);

namespace Commerce\Core\Configuration;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ConfigurationRevisionStore
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function activateStoreJson(
        int $storeId,
        string $namespace,
        string $configKey,
        array $payload,
        ?string $actorSubject = null,
    ): int {
        $namespace = $this->normalizeKey($namespace, 'namespace');
        $configKey = $this->normalizeKey($configKey, 'config key');
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $checksum = hash('sha256', $json);

        return $this->connection->transactional(function (Connection $db) use ($storeId, $namespace, $configKey, $json, $checksum, $actorSubject): int {
            $storePublicId = $db->fetchOne('SELECT public_id FROM mc_store WHERE id=? FOR UPDATE', [$storeId]);
            if (!is_string($storePublicId) || $storePublicId === '') {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4aa4f8267cce'));
            }

            $active = $db->fetchAssociative(
                "SELECT id,revision_number,payload,checksum_sha256 FROM mc_configuration_revision WHERE store_id=? AND namespace=? AND config_key=? AND status='active' ORDER BY revision_number DESC LIMIT 1 FOR UPDATE",
                [$storeId, $namespace, $configKey],
            );

            if (is_array($active)) {
                $activeJson = $active['payload'] ?? null;
                $activeChecksum = $active['checksum_sha256'] ?? null;
                if (is_string($activeJson) && is_string($activeChecksum) && hash_equals(strtolower($activeChecksum), hash('sha256', $activeJson))) {
                    try {
                        $activePayload = json_decode($activeJson, true, 64, JSON_THROW_ON_ERROR);
                        if (is_array($activePayload) && $activePayload === $payload) {
                            return (int) $active['id'];
                        }
                    } catch (\JsonException) {
                        // A malformed active revision is superseded by the new valid payload below.
                    }
                }
            }

            $latestRevision = (int) $db->fetchOne(
                'SELECT COALESCE(MAX(revision_number),0) FROM mc_configuration_revision WHERE store_id=? AND namespace=? AND config_key=? FOR UPDATE',
                [$storeId, $namespace, $configKey],
            );
            $nextRevision = $latestRevision + 1;
            $now = $this->now();
            if (is_array($active)) {
                $db->update('mc_configuration_revision', ['status' => 'superseded'], ['id' => (int) $active['id']]);
            }

            $db->insert('mc_configuration_revision', [
                'public_id' => $this->publicIds->binary(),
                'store_id' => $storeId,
                'namespace' => $namespace,
                'config_key' => $configKey,
                'revision_number' => $nextRevision,
                'status' => 'active',
                'payload' => $json,
                'checksum_sha256' => $checksum,
                'actor_subject' => $actorSubject,
                'parent_revision_id' => is_array($active) ? (int) $active['id'] : null,
                'created_at' => $now,
                'activated_at' => $now,
            ]);
            $revisionId = (int) $db->lastInsertId();

            // MySQL's native JSON type normalizes the textual representation on write.
            // The integrity checksum must therefore cover the representation actually stored
            // by the database, not the pre-insert JSON string. MariaDB may preserve the input
            // representation, so this works consistently on both engines.
            $storedJson = $db->fetchOne('SELECT payload FROM mc_configuration_revision WHERE id=?', [$revisionId]);
            if (!is_string($storedJson) || $storedJson === '') {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fc75d0c940f5'));
            }
            $storedChecksum = hash('sha256', $storedJson);
            if (!hash_equals($checksum, $storedChecksum)) {
                $db->update('mc_configuration_revision', ['checksum_sha256' => $storedChecksum], ['id' => $revisionId]);
            }

            $db->executeStatement(
                "INSERT INTO mc_entity_metadata (entity_type,entity_public_id,namespace,meta_key,value_json,updated_at) VALUES ('store',?,?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)",
                [$storePublicId, $namespace, $configKey, $json, $now],
            );

            return $revisionId;
        });
    }

    /**
     * Returns the newest revision whose JSON and SHA-256 checksum are both valid.
     * Corrupted/partially-written configuration rows are ignored so optional settings can never break the storefront.
     *
     * @return array<string,mixed>|null
     */
    public function latestValidPayload(int $storeId, string $namespace, string $configKey, int $limit = 25): ?array
    {
        $namespace = $this->normalizeKey($namespace, 'namespace');
        $configKey = $this->normalizeKey($configKey, 'config key');
        $limit = max(1, min(100, $limit));

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT payload,checksum_sha256 FROM mc_configuration_revision WHERE store_id=? AND namespace=? AND config_key=? ORDER BY revision_number DESC LIMIT {$limit}",
                [$storeId, $namespace, $configKey],
            );
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            $json = $row['payload'] ?? null;
            $checksum = $row['checksum_sha256'] ?? null;
            if (!is_string($json) || $json === '' || !is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/i', $checksum)) {
                continue;
            }
            if (!hash_equals(strtolower($checksum), hash('sha256', $json))) {
                continue;
            }
            try {
                $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($payload)) {
                return $payload;
            }
        }

        return null;
    }

    /** @return list<array{id:int,public_id:string,revision_number:int,status:string,actor_subject:?string,created_at:string,activated_at:?string}> */
    public function history(int $storeId, string $namespace, string $configKey, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id,public_id,revision_number,status,actor_subject,created_at,activated_at FROM mc_configuration_revision WHERE store_id=? AND namespace=? AND config_key=? ORDER BY revision_number DESC LIMIT {$limit}",
            [$storeId, $this->normalizeKey($namespace, 'namespace'), $this->normalizeKey($configKey, 'config key')],
        );

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'public_id' => Uuid::fromBinary((string) $row['public_id'])->toRfc4122(),
                'revision_number' => (int) $row['revision_number'],
                'status' => (string) $row['status'],
                'actor_subject' => $row['actor_subject'] !== null ? (string) $row['actor_subject'] : null,
                'created_at' => (string) $row['created_at'],
                'activated_at' => $row['activated_at'] !== null ? (string) $row['activated_at'] : null,
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    public function payload(int $storeId, int $revisionId, string $namespace, string $configKey): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT payload,checksum_sha256 FROM mc_configuration_revision WHERE id=? AND store_id=? AND namespace=? AND config_key=? LIMIT 1',
            [$revisionId, $storeId, $this->normalizeKey($namespace, 'namespace'), $this->normalizeKey($configKey, 'config key')],
        );
        if (!is_array($row) || !is_string($row['payload'] ?? null) || !is_string($row['checksum_sha256'] ?? null)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fc75d0c940f5'));
        }
        $json = (string) $row['payload'];
        if (!hash_equals(strtolower((string) $row['checksum_sha256']), hash('sha256', $json))) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.77fb611d30da'));
        }
        $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5b0ba1c10460'));
        }
        return $payload;
    }

    /** @param callable(array<string,mixed>):array<string,mixed>|null $validator */
    public function rollback(
        int $storeId,
        int $revisionId,
        string $namespace,
        string $configKey,
        ?string $actorSubject = null,
        ?callable $validator = null,
    ): int {
        $payload = $this->payload($storeId, $revisionId, $namespace, $configKey);
        if ($validator !== null) {
            $payload = $validator($payload);
        }
        return $this->activateStoreJson($storeId, $namespace, $configKey, $payload, $actorSubject);
    }

    private function normalizeKey(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z0-9_.-]{1,64}$/', $value)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.620108b53387') . $label . '.');
        }
        return $value;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
