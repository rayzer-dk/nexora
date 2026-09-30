<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Undo;

use Doctrine\DBAL\Connection;

/**
 * Short-lived "undo" tickets. A ticket stores what a single reversible admin action changed (never more
 * than the previous values), belongs to one administrator in one store and can be used once.
 */
final readonly class AdminUndoService
{
    public const TTL_SECONDS = 900;

    public function __construct(private Connection $db)
    {
    }

    /** @param array<string,mixed> $payload */
    public function remember(int $storeId, int $adminId, string $kind, string $label, array $payload): int
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->executeStatement('UPDATE mc_admin_undo SET used_at=? WHERE store_id=? AND admin_id=? AND used_at IS NULL', [$now, $storeId, $adminId]);
        $this->db->insert('mc_admin_undo', [
            'store_id' => $storeId,
            'admin_id' => $adminId,
            'kind' => $kind,
            'label' => mb_substr($label, 0, 190),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s.u', time() + self::TTL_SECONDS),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array{id:int,kind:string,label:string}|null the newest ticket that can still be used */
    public function latest(int $storeId, int $adminId, int $maxAgeSeconds = self::TTL_SECONDS): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id,kind,label FROM mc_admin_undo WHERE store_id=? AND admin_id=? AND used_at IS NULL AND expires_at>? AND created_at>? ORDER BY id DESC LIMIT 1', [$storeId, $adminId, gmdate('Y-m-d H:i:s.u'), gmdate('Y-m-d H:i:s.u', time() - $maxAgeSeconds)]);

        return is_array($row) ? ['id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'label' => (string) $row['label']] : null;
    }

    /**
     * Marks the ticket as used and returns it. The UPDATE is the lock: only one request can win it.
     *
     * @return array{kind:string,label:string,payload:array<string,mixed>}|null
     */
    public function claim(int $id, int $storeId, int $adminId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT kind,label,payload FROM mc_admin_undo WHERE id=? AND store_id=? AND admin_id=? AND used_at IS NULL AND expires_at>?', [$id, $storeId, $adminId, gmdate('Y-m-d H:i:s.u')]);
        if (!is_array($row)) {
            return null;
        }
        $won = $this->db->executeStatement('UPDATE mc_admin_undo SET used_at=? WHERE id=? AND used_at IS NULL', [gmdate('Y-m-d H:i:s.u'), $id]);
        if ($won !== 1) {
            return null;
        }
        $payload = json_decode((string) $row['payload'], true);

        return ['kind' => (string) $row['kind'], 'label' => (string) $row['label'], 'payload' => is_array($payload) ? $payload : []];
    }

    public function purgeExpired(): int
    {
        return (int) $this->db->executeStatement('DELETE FROM mc_admin_undo WHERE expires_at<?', [gmdate('Y-m-d H:i:s.u', time() - 86400)]);
    }
}
