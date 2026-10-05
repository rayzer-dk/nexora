<?php

declare(strict_types=1);

namespace Commerce\Modules\Feeds\Application;

use Commerce\Core\Configuration\SystemSettingStore;

/**
 * What goes into each trade channel and at what price: markup in percent (a marketplace commission is paid by the price),
 * a price corridor, categories and brands that stay out, and "only in stock". Set per channel; the shop prices are never touched.
 */
final class FeedRulesService
{
    /** @var array<int,array<string,array<string,mixed>>> */
    private array $cache = [];

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    /** @return array{markup_percent:float,min_price_minor:int,max_price_minor:int,exclude_categories:list<int>,exclude_brands:list<string>,in_stock_only:bool}
     */
    public function forPlatform(int $storeId, string $platform): array
    {
        $all = $this->all($storeId);
        $r = is_array($all[$platform] ?? null) ? $all[$platform] : [];

        return [
            'markup_percent' => max(-90.0, min(500.0, (float) ($r['markup_percent'] ?? 0))),
            'min_price_minor' => max(0, (int) ($r['min_price_minor'] ?? 0)),
            'max_price_minor' => max(0, (int) ($r['max_price_minor'] ?? 0)),
            'exclude_categories' => array_values(array_unique(array_filter(array_map('intval', (array) ($r['exclude_categories'] ?? [])), static fn (int $i): bool => $i > 0))),
            'exclude_brands' => array_values(array_filter(array_map(static fn (mixed $b): string => mb_strtolower(trim((string) $b)), (array) ($r['exclude_brands'] ?? [])), static fn (string $b): bool => $b !== '')),
            'in_stock_only' => (bool) ($r['in_stock_only'] ?? false),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function all(int $storeId): array
    {
        return $this->cache[$storeId] ??= ($this->store->getArray('feeds.rules.' . $storeId) ?? []);
    }

    /** @param array<string,mixed> $input fields of one channel, prices in whole currency units */
    public function save(int $storeId, string $platform, array $input): void
    {
        if (preg_match('/^[a-z0-9_]{2,30}$/D', $platform) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.feedrules.bad_platform'));
        }
        $money = static fn (mixed $v): int => max(0, (int) round(((float) str_replace(',', '.', trim((string) $v))) * 100));
        $brands = preg_split('/[\r\n,;]+/', (string) ($input['exclude_brands'] ?? '')) ?: [];
        $all = $this->all($storeId);
        $all[$platform] = [
            'markup_percent' => max(-90.0, min(500.0, (float) str_replace(',', '.', (string) ($input['markup_percent'] ?? '0')))),
            'min_price_minor' => $money($input['min_price'] ?? 0),
            'max_price_minor' => $money($input['max_price'] ?? 0),
            'exclude_categories' => array_values(array_unique(array_filter(array_map('intval', (array) ($input['exclude_categories'] ?? [])), static fn (int $i): bool => $i > 0))),
            'exclude_brands' => array_slice(array_values(array_filter(array_map(static fn (string $b): string => mb_substr(trim($b), 0, 120), $brands), static fn (string $b): bool => $b !== '')), 0, 100),
            'in_stock_only' => !empty($input['in_stock_only']),
        ];
        if (!$this->store->setArray('feeds.rules.' . $storeId, $all)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
        }
        unset($this->cache[$storeId]);
    }

    /**
     * Applies the rules to the canonical products: leaves out what the rules exclude and re-prices the rest.
     *
     * @param list<array<string,mixed>> $products
     * @return array{0:list<array<string,mixed>>,1:int} products and how many were left out
     */
    public function apply(int $storeId, string $platform, array $products): array
    {
        $r = $this->forPlatform($storeId, $platform);
        $out = [];
        $left = 0;
        foreach ($products as $p) {
            $price = (int) $p['price_minor'];
            $categoryIds = array_map(static fn (array $c): int => (int) $c['id'], (array) ($p['categories'] ?? []));
            if (($r['in_stock_only'] && (float) $p['quantity'] <= 0)
                || ($r['min_price_minor'] > 0 && $price < $r['min_price_minor'])
                || ($r['max_price_minor'] > 0 && $price > $r['max_price_minor'])
                || ($r['exclude_categories'] !== [] && array_intersect($categoryIds, $r['exclude_categories']) !== [])
                || ($r['exclude_brands'] !== [] && in_array(mb_strtolower((string) $p['brand']), $r['exclude_brands'], true))) {
                ++$left;
                continue;
            }
            if ($r['markup_percent'] !== 0.0) {
                $factor = 1 + $r['markup_percent'] / 100;
                $p['price_minor'] = (int) round($price * $factor);
                $p['price'] = number_format($p['price_minor'] / 100, 2, '.', '');
                $p['regular_price'] = $p['sale_price'] !== null ? number_format(round(((float) $p['regular_price']) * $factor * 100) / 100, 2, '.', '') : $p['price'];
                $p['sale_price'] = $p['sale_price'] !== null ? $p['price'] : null;
            }
            $out[] = $p;
        }

        return [$out, $left];
    }
}
