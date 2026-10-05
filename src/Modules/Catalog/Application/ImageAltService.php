<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Doctrine\DBAL\Connection;

/**
 * ALT text of product photos from a template ("{name} - {brand}, photo {n}"). Photos without their own ALT text show the template
 * result on the storefront at once (in the visitor's language); the batch tool can also write it into the empty fields for good.
 * Variables: {name} {brand} {category} {sku} {n} {store}.
 */
final class ImageAltService
{
    private const KEY = 'catalog.image_alt';
    public const VARIABLES = ['name', 'brand', 'category', 'sku', 'n', 'store'];

    /** @var array{enabled:bool,main:string,extra:string}|null */
    private ?array $cache = null;

    public function __construct(private readonly Connection $connection, private readonly SystemSettingStore $store)
    {
    }

    /** @return array{enabled:bool,main:string,extra:string} */
    public function settings(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $raw = $this->store->getArray(self::KEY) ?? [];
        } catch (\Throwable) {
            $raw = [];
        }
        $main = trim((string) ($raw['main'] ?? ''));
        $extra = trim((string) ($raw['extra'] ?? ''));

        return $this->cache = ['enabled' => (bool) ($raw['enabled'] ?? true), 'main' => $main !== '' ? $main : '{name}', 'extra' => $extra !== '' ? $extra : '{name} — {n}'];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $clean = static fn (mixed $v): string => trim(mb_substr(strip_tags((string) $v), 0, 240, 'UTF-8'));
        $this->store->setArray(self::KEY, ['enabled' => !empty($input['enabled']), 'main' => $clean($input['main'] ?? ''), 'extra' => $clean($input['extra'] ?? '')]);
        $this->cache = null;
    }

    /** @param array<string,string|int> $vars */
    public function render(string $template, array $vars): string
    {
        $text = (string) preg_replace_callback('/\{(name|brand|category|sku|n|store)\}/', static fn (array $m): string => (string) ($vars[$m[1]] ?? ''), $template);
        $text = trim((string) preg_replace(['/\s+/u', '/(\s[-—–,:]\s*)+(?=$|[-—–,:])/u'], [' ', ''], $text), " \t-—–,:");

        return mb_substr($text, 0, 240, 'UTF-8');
    }

    /** ALT text for a photo that has none of its own; null when automatic ALT is off. */
    public function fallback(string $name, int $position, string $brand = '', string $sku = '', string $category = ''): ?string
    {
        $s = $this->settings();
        if (!$s['enabled']) {
            return null;
        }

        return $this->render($position <= 1 ? $s['main'] : $s['extra'], ['name' => $name, 'brand' => $brand, 'sku' => $sku, 'category' => $category, 'n' => $position]);
    }

    /**
     * Photos with an empty ALT field (all photos with $overwrite) and the text the template would give them.
     *
     * @return list<array{product_id:int,asset_id:int,name:string,position:int,current:string,alt:string}>
     */
    public function plan(int $storeId, string $locale, bool $overwrite, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT pm.product_id,pm.media_asset_id,pm.role,pm.sort_order,COALESCE(pm.alt_text,'') AS alt_text,
                    COALESCE(pt.name,pt2.name,'') AS name,COALESCE(b.name,'') AS brand,
                    COALESCE((SELECT v.sku FROM mc_product_variant v WHERE v.product_id=p.id ORDER BY v.sort_order,v.id LIMIT 1),'') AS sku,
                    COALESCE((SELECT COALESCE(ct.name,ct2.name) FROM mc_product_category pc
                              LEFT JOIN mc_category_translation ct ON ct.category_id=pc.category_id AND ct.locale=:loc AND ct.store_id=:store
                              LEFT JOIN mc_category_translation ct2 ON ct2.id=(SELECT MIN(x.id) FROM mc_category_translation x WHERE x.category_id=pc.category_id)
                              WHERE pc.product_id=p.id ORDER BY pc.is_primary DESC,pc.sort_order LIMIT 1),'') AS category
             FROM mc_product_media pm
             JOIN mc_product p ON p.id=pm.product_id
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=:store
             LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=:store AND pt.locale=:loc
             LEFT JOIN mc_product_translation pt2 ON pt2.id=(SELECT MIN(y.id) FROM mc_product_translation y WHERE y.product_id=p.id AND y.store_id=:store)
             LEFT JOIN mc_brand b ON b.id=p.brand_id
             WHERE pm.role IN ('primary','gallery')
             ORDER BY pm.product_id,(pm.role='primary') DESC,pm.sort_order,pm.media_asset_id",
            ['store' => $storeId, 'loc' => $locale],
        );
        $store = (string) $this->connection->fetchOne('SELECT name FROM mc_store WHERE id=?', [$storeId]);
        $out = [];
        $counter = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            $counter[$pid] = ($counter[$pid] ?? 0) + 1;
            $current = trim((string) $r['alt_text']);
            if (($current !== '' && !$overwrite) || (string) $r['name'] === '') {
                continue;
            }
            $s = $this->settings();
            $alt = $this->render($counter[$pid] <= 1 ? $s['main'] : $s['extra'], ['name' => $r['name'], 'brand' => $r['brand'], 'category' => $r['category'], 'sku' => $r['sku'], 'n' => $counter[$pid], 'store' => $store]);
            if ($alt === '' || $alt === $current) {
                continue;
            }
            $out[] = ['product_id' => $pid, 'asset_id' => (int) $r['media_asset_id'], 'name' => (string) $r['name'], 'position' => $counter[$pid], 'current' => $current, 'alt' => $alt];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function apply(int $storeId, string $locale, bool $overwrite, int $limit = 5000): int
    {
        $count = 0;
        foreach ($this->plan($storeId, $locale, $overwrite, $limit) as $row) {
            $count += $this->connection->executeStatement(
                'UPDATE mc_product_media SET alt_text=? WHERE product_id=? AND media_asset_id=?',
                [$row['alt'], $row['product_id'], $row['asset_id']],
            );
        }

        return $count;
    }
}
