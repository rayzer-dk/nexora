<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Application;

use Doctrine\DBAL\Connection;

/**
 * Tax classes of products, the VAT rates per country and how the storefront shows prices.
 * Prices are always entered and charged as the final, tax-inclusive amount; the display mode only decides
 * how that price is presented next to its VAT.
 */
final readonly class TaxSettingsService
{
    public const DISPLAY_MODES = ['price_only', 'gross_with_breakdown', 'net_with_gross', 'net'];

    public function __construct(private Connection $db)
    {
    }

    /** @return list<array{id:int,code:string,name:string,kind:string}> */
    public function classes(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'kind' => (string) $r['kind']], $this->db->fetchAllAssociative('SELECT id,code,name,kind FROM mc_tax_class WHERE enabled=1 ORDER BY id'));
    }

    /** @return list<array<string,mixed>> */
    public function rates(): array
    {
        return $this->db->fetchAllAssociative('SELECT r.id,r.country_code,r.region_code,r.name,r.rate_bps,r.priority,r.enabled,c.code AS class_code,c.name AS class_name FROM mc_tax_rate r JOIN mc_tax_class c ON c.id=r.tax_class_id ORDER BY r.country_code,c.id,r.priority,r.id');
    }

    public function addRate(int $classId, string $country, string $name, string $percent): void
    {
        $country = strtoupper(trim($country));
        $name = trim($name);
        $percent = str_replace(',', '.', trim($percent));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException('country_invalid');
        }
        if ($name === '' || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('name_invalid');
        }
        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $percent) !== 1 || (float) $percent > 100.0) {
            throw new \InvalidArgumentException('rate_invalid');
        }
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_tax_class WHERE id=? AND enabled=1', [$classId]) !== 1) {
            throw new \InvalidArgumentException('class_invalid');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->insert('mc_tax_rate', ['tax_class_id' => $classId, 'country_code' => $country, 'region_code' => null, 'name' => $name, 'rate_bps' => (int) round(((float) $percent) * 100), 'priority' => 100, 'valid_from' => '2000-01-01 00:00:00.000000', 'valid_to' => null, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function toggleRate(int $id): void
    {
        $this->db->executeStatement('UPDATE mc_tax_rate SET enabled=1-enabled, updated_at=? WHERE id=?', [gmdate('Y-m-d H:i:s.u'), $id]);
    }

    public function deleteRate(int $id): void
    {
        $this->db->delete('mc_tax_rate', ['id' => $id]);
    }

    public function displayMode(int $storeId): string
    {
        $mode = (string) $this->db->fetchOne('SELECT mtp.consumer_display_mode FROM mc_market_tax_policy mtp JOIN mc_market m ON m.id=mtp.market_id WHERE m.store_id=? ORDER BY m.id LIMIT 1', [$storeId]);

        return in_array($mode, self::DISPLAY_MODES, true) ? $mode : 'price_only';
    }

    public function setDisplayMode(int $storeId, string $mode): void
    {
        if (!in_array($mode, self::DISPLAY_MODES, true)) {
            throw new \InvalidArgumentException('mode_invalid');
        }
        $now = gmdate('Y-m-d H:i:s.u');
        foreach ($this->db->fetchFirstColumn('SELECT id FROM mc_market WHERE store_id=?', [$storeId]) as $marketId) {
            $this->db->executeStatement(
                'INSERT INTO mc_market_tax_policy (market_id, consumer_display_mode, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE consumer_display_mode=VALUES(consumer_display_mode), updated_at=VALUES(updated_at)',
                [(int) $marketId, $mode, $now],
            );
        }
    }

    public function productClassId(int $productId): int
    {
        return (int) $this->db->fetchOne('SELECT tax_class_id FROM mc_product WHERE id=?', [$productId]);
    }

    public function setProductClass(int $productId, int $classId): void
    {
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_tax_class WHERE id=? AND enabled=1', [$classId]) !== 1) {
            throw new \InvalidArgumentException('class_invalid');
        }
        $this->db->executeStatement('UPDATE mc_product SET tax_class_id=?, updated_at=? WHERE id=? AND tax_class_id<>?', [$classId, gmdate('Y-m-d H:i:s.u'), $productId, $classId]);
    }
}
