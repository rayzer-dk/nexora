<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Dashboard;

use Doctrine\DBAL\Connection;

/** Order and visibility of the dashboard blocks; every administrator keeps their own. */
final class DashboardLayout
{
    /** Default order. A key that is not here is ignored when a layout is saved or read. */
    public const WIDGETS = ['health', 'setup', 'quick', 'kpis', 'main', 'flow', 'attention', 'tops', 'orders'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Turns what a person sent (or what is stored) into a full layout: known keys only, no repeats,
     * keys that are missing appended in their default place, so a block added in a new version still shows.
     *
     * @param mixed $order
     * @param mixed $hidden
     * @return array{order:list<string>,hidden:list<string>}
     */
    public static function normalize(mixed $order, mixed $hidden): array
    {
        $known = array_flip(self::WIDGETS);
        $result = [];
        foreach (is_array($order) ? $order : [] as $key) {
            if (is_string($key) && isset($known[$key]) && !in_array($key, $result, true)) {
                $result[] = $key;
            }
        }
        foreach (self::WIDGETS as $key) {
            if (!in_array($key, $result, true)) {
                $result[] = $key;
            }
        }
        $off = [];
        foreach (is_array($hidden) ? $hidden : [] as $key) {
            if (is_string($key) && isset($known[$key]) && !in_array($key, $off, true)) {
                $off[] = $key;
            }
        }

        return ['order' => $result, 'hidden' => $off];
    }

    /** @return array{order:list<string>,hidden:list<string>} */
    public function load(int $adminId, int $storeId): array
    {
        try {
            $json = $this->db->fetchOne('SELECT layout_json FROM mc_admin_dashboard_layout WHERE admin_id=? AND store_id=?', [$adminId, $storeId]);
        } catch (\Throwable) {
            $json = false;
        }
        $data = is_string($json) ? json_decode($json, true) : null;
        $data = is_array($data) ? $data : [];

        return self::normalize($data['order'] ?? [], $data['hidden'] ?? []);
    }

    public function save(int $adminId, int $storeId, mixed $order, mixed $hidden): void
    {
        $layout = self::normalize($order, $hidden);
        $this->db->executeStatement(
            'INSERT INTO mc_admin_dashboard_layout (admin_id,store_id,layout_json,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE layout_json=VALUES(layout_json),updated_at=VALUES(updated_at)',
            [$adminId, $storeId, json_encode($layout, JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s.u')],
        );
    }

    public function reset(int $adminId, int $storeId): void
    {
        $this->db->delete('mc_admin_dashboard_layout', ['admin_id' => $adminId, 'store_id' => $storeId]);
    }
}
