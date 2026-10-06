<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/** Customer groups (retail, VIP, wholesale...): each can carry a percentage discount that shows in prices and is taken off in the cart. */
final class CustomerGroupService
{
    /** @var array<string,array{code:string,name:string,discount_bps:int,skip_sale_items:bool,sort_order:int}>|null */
    private ?array $cache = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,array{code:string,name:string,discount_bps:int,skip_sale_items:bool,sort_order:int}> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $rows = $this->db->fetchAllAssociative('SELECT code,name,discount_bps,skip_sale_items,sort_order FROM mc_customer_group ORDER BY sort_order,code');
        } catch (\Throwable) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['code']] = ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'discount_bps' => (int) $r['discount_bps'], 'skip_sale_items' => (int) $r['skip_sale_items'] === 1, 'sort_order' => (int) $r['sort_order']];
        }
        if (!isset($out['default'])) {
            $out = ['default' => ['code' => 'default', 'name' => 'Default', 'discount_bps' => 0, 'skip_sale_items' => false, 'sort_order' => 0]] + $out;
        }

        return $this->cache = $out;
    }

    public function get(string $code): array
    {
        return $this->all()[strtolower($code)] ?? $this->all()['default'];
    }

    public function discountBps(string $code): int
    {
        return $this->get($code)['discount_bps'];
    }

    /** @param array<string,mixed> $in */
    public function save(string $code, array $in): string
    {
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code) !== 1) {
            throw new \DomainException(CanonicalUiText::get('admin.customer_groups.invalid_code'));
        }
        $name = trim(mb_substr(strip_tags((string) ($in['name'] ?? '')), 0, 190, 'UTF-8')) ?: $code;
        $percent = max(0.0, min(90.0, (float) str_replace(',', '.', (string) ($in['discount_percent'] ?? '0'))));
        $row = ['name' => $name, 'discount_bps' => (int) round($percent * 100), 'skip_sale_items' => !empty($in['skip_sale_items']) ? 1 : 0, 'sort_order' => (int) ($in['sort_order'] ?? 100)];
        if (isset($this->all()[$code]) && $this->db->fetchOne('SELECT 1 FROM mc_customer_group WHERE code=?', [$code])) {
            $this->db->update('mc_customer_group', $row, ['code' => $code]);
        } else {
            $this->db->insert('mc_customer_group', $row + ['code' => $code, 'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
        }
        $this->cache = null;

        return $code;
    }

    public function delete(string $code): void
    {
        $code = strtolower(trim($code));
        if ($code === 'default') {
            throw new \DomainException(CanonicalUiText::get('admin.customer_groups.default_locked'));
        }
        $this->db->executeStatement("UPDATE mc_customer SET customer_group_code='default' WHERE customer_group_code=?", [$code]);
        $this->db->delete('mc_customer_group', ['code' => $code]);
        $this->cache = null;
    }

    /** @return array<string,int> group code => customers */
    public function counts(): array
    {
        $rows = $this->db->fetchAllKeyValue("SELECT LOWER(customer_group_code),COUNT(*) FROM mc_customer GROUP BY LOWER(customer_group_code)");

        return array_map('intval', $rows);
    }
}
