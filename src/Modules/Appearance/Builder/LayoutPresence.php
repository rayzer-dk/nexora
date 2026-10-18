<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Who has a builder page open: each open page reports every 30 s; nobody locks anything, so nothing has to be released. */
final readonly class LayoutPresence
{
    private const SEEN_SECONDS = 90;

    public function __construct(private Connection $db)
    {
    }

    /**
     * Records the caller on the page and returns the other editors of it.
     *
     * @return list<array{name:string, since:string}>
     */
    public function touch(int $storeId, string $type, int $adminId): array
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $fresh = gmdate('Y-m-d H:i:s.u', time() - self::SEEN_SECONDS);
        $this->db->executeStatement('DELETE FROM mc_layout_presence WHERE last_seen_at < ?', [gmdate('Y-m-d H:i:s.u', time() - 86400)]);
        $key = ['store_id' => $storeId, 'layout_type' => $type, 'admin_user_id' => $adminId];
        $update = 'UPDATE mc_layout_presence SET first_seen_at = CASE WHEN last_seen_at < ? THEN ? ELSE first_seen_at END, last_seen_at = ? WHERE store_id = ? AND layout_type = ? AND admin_user_id = ?';
        $params = [$fresh, $now, $now, $storeId, $type, $adminId];
        if ($this->db->executeStatement($update, $params) === 0) {
            try {
                $this->db->insert('mc_layout_presence', $key + ['first_seen_at' => $now, 'last_seen_at' => $now]);
            } catch (UniqueConstraintViolationException) {
                // A second tab of the same editor inserted the row between our update and insert.
                $this->db->executeStatement($update, $params);
            }
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT COALESCE(NULLIF(u.display_name, ''), u.email) AS name, p.first_seen_at FROM mc_layout_presence p JOIN mc_admin_user u ON u.id = p.admin_user_id WHERE p.store_id = ? AND p.layout_type = ? AND p.admin_user_id <> ? AND p.last_seen_at >= ? ORDER BY p.first_seen_at, u.id",
            [$storeId, $type, $adminId, $fresh],
        );

        return array_map(static fn(array $row): array => [
            'name' => (string) $row['name'],
            'since' => (new \DateTimeImmutable((string) $row['first_seen_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ], $rows);
    }

    public function leave(int $storeId, string $type, int $adminId): void
    {
        $this->db->delete('mc_layout_presence', ['store_id' => $storeId, 'layout_type' => $type, 'admin_user_id' => $adminId]);
    }
}
