<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Doctrine\DBAL\Connection;

/** Self-pickup points of a store (shops, warehouses, parcel desks) offered as the "Self pickup" delivery method. */
final readonly class PickupPointRepository
{
    public function __construct(private Connection $db, private StorefrontContactSettings $contacts)
    {
    }

    /**
     * Points offered at checkout. Self pickup is always available: when the owner has not entered any pickup point yet,
     * the store itself (name + public address from the contact settings) is offered as a single virtual point (id 0).
     *
     * @return list<array{id:int,name:string,city:string,address:string,working_hours:string,phone:string,sort_order:int,enabled:bool}>
     */
    public function forCheckout(int $storeId, string $storeName): array
    {
        $points = $this->all($storeId, true);
        if ($points !== []) {
            return $points;
        }
        $view = $this->contacts->publicView($storeId);

        return [['id' => 0, 'name' => $storeName, 'city' => '', 'address' => (string) $view['address'], 'working_hours' => implode(', ', $view['hours']), 'phone' => (string) ($view['phones'][0]['label'] ?? ''), 'sort_order' => 0, 'enabled' => true]];
    }

    /** @return array{id:int,name:string,city:string,address:string,working_hours:string,phone:string,sort_order:int,enabled:bool}|null */
    public function resolveForCheckout(int $storeId, string $storeName, int $id): ?array
    {
        foreach ($this->forCheckout($storeId, $storeName) as $point) {
            if ($point['id'] === $id) {
                return $point;
            }
        }

        return null;
    }

    /** @return list<array{id:int,name:string,city:string,address:string,working_hours:string,phone:string,sort_order:int,enabled:bool}> */
    public function all(int $storeId, bool $onlyEnabled = false): array
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT id,name,city,address,working_hours,phone,sort_order,enabled FROM mc_pickup_point WHERE store_id=?' . ($onlyEnabled ? ' AND enabled=1' : '') . ' ORDER BY sort_order,id', [$storeId]);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'city' => (string) ($r['city'] ?? ''),
            'address' => (string) $r['address'],
            'working_hours' => (string) ($r['working_hours'] ?? ''),
            'phone' => (string) ($r['phone'] ?? ''),
            'sort_order' => (int) $r['sort_order'],
            'enabled' => (bool) $r['enabled'],
        ], $rows);
    }

    /** @param array<string,mixed> $in */
    public function save(int $storeId, ?int $id, array $in): int
    {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 190);
        $address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 500);
        if ($name === '' || $address === '') {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.storefront_contacts.error.pickup_required'));
        }
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($phone !== '' && preg_match('/^\+?[0-9 ()\-]{5,32}$/', $phone) !== 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.cw.error.phone'));
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $row = [
            'name' => $name,
            'city' => mb_substr(trim((string) ($in['city'] ?? '')), 0, 190) ?: null,
            'address' => $address,
            'working_hours' => mb_substr(trim((string) ($in['working_hours'] ?? '')), 0, 500) ?: null,
            'phone' => $phone !== '' ? $phone : null,
            'sort_order' => max(-9999, min(9999, (int) ($in['sort_order'] ?? 0))),
            'enabled' => !empty($in['enabled']) ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($id !== null && (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_pickup_point WHERE id=? AND store_id=?', [$id, $storeId]) > 0) {
            $this->db->update('mc_pickup_point', $row, ['id' => $id, 'store_id' => $storeId]);

            return $id;
        }
        $this->db->insert('mc_pickup_point', ['store_id' => $storeId, 'created_at' => $now] + $row);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $storeId, int $id): void
    {
        $this->db->delete('mc_pickup_point', ['id' => $id, 'store_id' => $storeId]);
    }
}
