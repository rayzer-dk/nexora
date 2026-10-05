<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\I18n\CanonicalUiText;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Product badges: automatic "new", "sale" and "bestseller" rules plus manual badges assigned to products.
 * A store without configured rules uses the built-in defaults, so the storefront always shows sensible badges.
 */
final class ProductBadgeService
{
    public const KINDS = ['new', 'sale', 'bestseller', 'manual'];
    public const TONES = ['primary', 'success', 'danger', 'warning', 'info', 'neutral'];

    /** @var array<int,list<array<string,mixed>>> */
    private array $rules = [];
    /** @var array<string,array<int,true>> */
    private array $sets = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function defaults(): array
    {
        return [
            ['code' => 'sale', 'kind' => 'sale', 'tone' => 'danger', 'labels' => [], 'window_days' => 0, 'min_sold' => 0, 'priority' => 10, 'enabled' => 1],
            ['code' => 'new', 'kind' => 'new', 'tone' => 'primary', 'labels' => [], 'window_days' => 14, 'min_sold' => 0, 'priority' => 20, 'enabled' => 1],
            ['code' => 'bestseller', 'kind' => 'bestseller', 'tone' => 'warning', 'labels' => [], 'window_days' => 30, 'min_sold' => 5, 'priority' => 30, 'enabled' => 1],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function rules(int $storeId, bool $includeDisabled = false): array
    {
        $key = $storeId * 2 + ($includeDisabled ? 1 : 0);
        if (isset($this->rules[$key])) {
            return $this->rules[$key];
        }
        try {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM mc_product_badge WHERE store_id=? ORDER BY priority,id', [$storeId]);
        } catch (\Throwable) {
            $rows = [];
        }
        if ($rows === []) {
            $list = $this->defaults();
        } else {
            $list = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'code' => (string) $r['code'], 'kind' => (string) $r['kind'], 'tone' => (string) $r['tone'],
                'labels' => json_decode((string) $r['labels_json'], true) ?: [], 'window_days' => (int) $r['window_days'],
                'min_sold' => (int) $r['min_sold'], 'priority' => (int) $r['priority'], 'enabled' => (int) $r['enabled'], 'icon' => (string) ($r['icon'] ?? ''),
            ], $rows);
        }
        if (!$includeDisabled) {
            $list = array_values(array_filter($list, static fn (array $r): bool => (bool) $r['enabled']));
        }

        return $this->rules[$key] = $list;
    }

    /** Persist the built-in defaults so the merchant can edit them (idempotent). */
    public function materializeDefaults(int $storeId): void
    {
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_badge WHERE store_id=?', [$storeId]) > 0) {
            return;
        }
        foreach ($this->defaults() as $d) {
            $this->save($storeId, null, $d);
        }
    }

    /** Readable text colour (black/white) for a #rrggbb background. */
    public static function contrastOn(string $hex): string
    {
        $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
        $lin = static fn (int $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin((int) $r) + 0.7152 * $lin((int) $g) + 0.0722 * $lin((int) $b);

        // Pick whichever of black/white gives the higher WCAG contrast ratio.
        return (1.05 / ($l + 0.05)) > (($l + 0.05) / 0.05) ? '#ffffff' : '#111111';
    }

    /** @param array<string,mixed> $d */
    public function save(int $storeId, ?int $id, array $d): int
    {
        $code = preg_replace('/[^a-z0-9_-]/', '', mb_strtolower(trim((string) ($d['code'] ?? '')))) ?? '';
        $kind = (string) ($d['kind'] ?? 'manual');
        $tone = (string) ($d['tone'] ?? 'primary');
        if ($code === '' || !in_array($kind, self::KINDS, true)) {
            throw new \DomainException('badge_invalid');
        }
        $labels = [];
        foreach ((array) ($d['labels'] ?? []) as $locale => $text) {
            $text = mb_substr(trim(strip_tags((string) $text)), 0, 40);
            if ($text !== '' && preg_match('/^[a-z]{2}-[A-Z]{2}$|^default$/', (string) $locale) === 1) {
                $labels[(string) $locale] = $text;
            }
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $row = [
            'kind' => $kind, 'tone' => in_array($tone, self::TONES, true) ? $tone : (preg_match('/^#[0-9a-fA-F]{6}$/', $tone) === 1 ? strtolower($tone) : 'primary'),
            'labels_json' => json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'icon' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', (string) ($d['icon'] ?? '')) === 1 ? (string) $d['icon'] : null,
            'window_days' => max(0, min(3650, (int) ($d['window_days'] ?? 30))), 'min_sold' => max(0, min(1000000, (int) ($d['min_sold'] ?? 5))),
            'priority' => max(-1000, min(1000, (int) ($d['priority'] ?? 100))), 'enabled' => !empty($d['enabled']) ? 1 : 0, 'updated_at' => $now,
        ];
        if ($id !== null && $this->db->fetchOne('SELECT id FROM mc_product_badge WHERE id=? AND store_id=?', [$id, $storeId]) !== false) {
            $this->db->update('mc_product_badge', $row, ['id' => $id, 'store_id' => $storeId]);
        } else {
            $this->db->insert('mc_product_badge', $row + ['store_id' => $storeId, 'code' => $code, 'created_at' => $now]);
            $id = (int) $this->db->lastInsertId();
        }
        $this->rules = [];

        return (int) $id;
    }

    public function delete(int $storeId, int $id): void
    {
        $this->db->delete('mc_product_badge', ['id' => $id, 'store_id' => $storeId]);
        $this->rules = [];
    }

    /** @param list<int> $productIds */
    public function assign(int $storeId, int $badgeId, array $productIds): void
    {
        if ($this->db->fetchOne("SELECT id FROM mc_product_badge WHERE id=? AND store_id=? AND kind='manual'", [$badgeId, $storeId]) === false) {
            throw new \DomainException('badge_invalid');
        }
        $this->db->transactional(function (Connection $db) use ($storeId, $badgeId, $productIds): void {
            $db->delete('mc_product_badge_product', ['badge_id' => $badgeId]);
            foreach (array_unique(array_filter($productIds, static fn (int $i): bool => $i > 0)) as $pid) {
                $db->executeStatement('INSERT IGNORE INTO mc_product_badge_product (badge_id,product_id) SELECT ?,sp.product_id FROM mc_store_product sp WHERE sp.product_id=? AND sp.store_id=?', [$badgeId, $pid, $storeId]);
            }
        });
        $this->sets = [];
    }

    /** @return list<int> */
    public function assignedProducts(int $badgeId): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT product_id FROM mc_product_badge_product WHERE badge_id=? ORDER BY product_id', [$badgeId]));
    }

    /**
     * Adds a `badges` list (label, tone, code) to every product card.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function decorate(array $items, int $storeId, string $locale): array
    {
        if ($items === []) {
            return $items;
        }
        $rules = $this->rules($storeId);
        foreach ($items as &$item) {
            $item['badges'] = [];
        }
        unset($item);
        foreach ($rules as $rule) {
            $set = $this->productSet($storeId, $rule, array_map(static fn (array $i): int => (int) ($i['internal_id'] ?? 0), $items));
            foreach ($items as &$item) {
                $id = (int) ($item['internal_id'] ?? 0);
                $match = match ($rule['kind']) {
                    'sale' => !empty($item['compare_at_price']),
                    default => isset($set[$id]),
                };
                if ($match && count($item['badges']) < 3) {
                    $item['badges'][] = ['code' => $rule['code'], 'tone' => str_starts_with((string) $rule['tone'], '#') ? 'custom' : $rule['tone'], 'color' => str_starts_with((string) $rule['tone'], '#') ? (string) $rule['tone'] : '', 'fg' => str_starts_with((string) $rule['tone'], '#') ? self::contrastOn((string) $rule['tone']) : '', 'label' => $this->label($rule, $locale), 'icon' => (string) ($rule['icon'] ?? '')];
                }
            }
            unset($item);
        }

        return $items;
    }

    /** @param array<string,mixed> $rule */
    public function label(array $rule, string $locale): string
    {
        $labels = (array) ($rule['labels'] ?? []);
        $text = $labels[$locale] ?? $labels['default'] ?? '';
        if ($text !== '') {
            return (string) $text;
        }

        return match ($rule['kind']) {
            'sale' => CanonicalUiText::get('sale'),
            'new' => CanonicalUiText::get('badge_new'),
            'bestseller' => CanonicalUiText::get('badge_bestseller'),
            default => (string) $rule['code'],
        };
    }

    /**
     * @param array<string,mixed> $rule
     * @param list<int> $ids
     * @return array<int,true>
     */
    private function productSet(int $storeId, array $rule, array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
        if ($ids === [] || $rule['kind'] === 'sale') {
            return [];
        }
        $key = $storeId . ':' . $rule['code'] . ':' . md5(implode(',', $ids));
        if (isset($this->sets[$key])) {
            return $this->sets[$key];
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $days = max(1, (int) $rule['window_days']);
        try {
            $found = match ($rule['kind']) {
                'new' => $this->db->fetchFirstColumn("SELECT p.id FROM mc_product p LEFT JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.id IN ($place) AND COALESCE(sp.published_at,p.created_at)>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL $days DAY)", [$storeId, ...$ids]),
                'bestseller' => $this->db->fetchFirstColumn("SELECT oi.product_id FROM mc_sales_order_item oi JOIN mc_sales_order o ON o.id=oi.order_id AND o.store_id=? AND o.status NOT IN ('cancelled','canceled') WHERE oi.product_id IN ($place) AND o.created_at>=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL $days DAY) GROUP BY oi.product_id HAVING SUM(oi.quantity)>=?", [$storeId, ...$ids, max(1, (int) $rule['min_sold'])]),
                'manual' => isset($rule['id']) ? $this->db->fetchFirstColumn("SELECT product_id FROM mc_product_badge_product WHERE badge_id=? AND product_id IN ($place)", [(int) $rule['id'], ...$ids]) : [],
                default => [],
            };
        } catch (\Throwable) {
            $found = [];
        }
        $set = [];
        foreach ($found as $id) {
            $set[(int) $id] = true;
        }

        return $this->sets[$key] = $set;
    }
}
