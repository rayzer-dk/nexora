<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Source\File;

/**
 * Turns the product export of another platform (a CSV or Excel file) into one common shape: Shopify, WooCommerce, PrestaShop, Prom.ua, Horoshop,
 * or any table with recognisable column names. Only names of columns are matched, so a renamed column is simply not used.
 *
 * A normalised product: key, sku, name, description, short_description, price, old_price, stock (int|null), enabled, weight, gtin, mpn, brand,
 * categories (list of paths, each a list of names), images (absolute URLs), slug, legacy_paths, meta_title, meta_description, attributes (name => value), options (name => values).
 */
final class PlatformCatalogProfiles
{
    public const PLATFORMS = ['shopify', 'woocommerce', 'prestashop', 'prom', 'horoshop', 'generic'];

    /** @var array<string,list<string>> column aliases of the generic reader (also used by Horoshop and as the fallback of the other profiles) */
    private const GENERIC = [
        'sku' => ['sku', 'артикул', 'код', 'код товара', 'код_товара', 'код_товару', 'reference', 'reference #', 'model', 'модель', 'vendor code', 'vendorcode', 'id товару'],
        'name' => ['name', 'name *', 'title', 'назва', 'название', 'название_позиции', 'назва_позиції', 'назва товару', 'название товара', 'product name', 'назва (ua)', 'название (ua)', 'назва_ua', 'name_ua', 'name_uk'],
        'name_ru' => ['назва (ru)', 'название (ru)', 'назва_ru', 'name_ru', 'название_ru'],
        'description' => ['description', 'опис', 'описание', 'body (html)', 'опис (ua)', 'описание (ua)', 'опис_ua', 'description_ua', 'full description'],
        'description_ru' => ['опис (ru)', 'описание (ru)', 'опис_ru', 'description_ru'],
        'short_description' => ['short description', 'short_description', 'короткий опис', 'краткое описание'],
        'price' => ['price', 'regular price', 'ціна', 'цена', 'variant price', 'price tax excluded', 'price tax excl.', 'price tax excl', 'роздрібна ціна', 'розничная цена'],
        'old_price' => ['compare at price', 'variant compare at price', 'old price', 'oldprice', 'стара ціна', 'старая цена', 'ціна до знижки', 'price before discount'],
        'sale_price' => ['sale price'],
        'stock' => ['stock', 'quantity', 'кількість', 'количество', 'залишок', 'остаток', 'qty', 'variant inventory qty', 'stock quantity'],
        'availability' => ['наявність', 'наличие', 'availability', 'in stock?'],
        'enabled' => ['published', 'active', 'active (0/1)', 'status', 'статус', 'відображати', 'відображення', 'показувати', 'visible', 'available for order (0 = no, 1 = yes)'],
        'brand' => ['brand', 'vendor', 'manufacturer', 'виробник', 'производитель', 'бренд'],
        'category' => ['category', 'categories', 'categories (x,y,z...)', 'product category', 'type', 'розділ', 'раздел', 'категорія', 'категория', 'назва_групи', 'название_группы', 'group', 'группа', 'група'],
        'images' => ['images', 'image src', 'image', 'image urls (x,y,z...)', 'photo', 'фото', 'зображення', 'изображение', 'ссылка_изображения', 'посилання_зображення', 'картинки', 'зображення (url)'],
        'slug' => ['handle', 'slug', 'url rewritten', 'url', 'seo url', 'seo_url', 'псевдонім', 'алиас'],
        'meta_title' => ['seo title', 'meta title', 'meta_title', 'seo заголовок'],
        'meta_description' => ['seo description', 'meta description', 'meta_description', 'seo опис'],
        'gtin' => ['variant barcode', 'barcode', 'ean', 'ean13', 'ean-13 or jan barcode', 'gtin', 'upc', 'штрихкод'],
        'mpn' => ['mpn', 'supplier reference #', 'артикул виробника'],
        'weight' => ['weight', 'weight (kg)', 'вага', 'вес', 'вага, кг', 'вес,кг', 'вес, кг', 'variant grams'],
    ];

    /** @param list<string> $headers */
    public static function detect(array $headers): string
    {
        $h = array_map([self::class, 'norm'], $headers);
        $has = static fn (string ...$names): bool => count(array_intersect($names, $h)) === count($names);
        if ($has('handle', 'title') && (in_array('variant sku', $h, true) || in_array('variant price', $h, true))) {
            return 'shopify';
        }
        if (in_array('regular price', $h, true) && in_array('type', $h, true) && (in_array('sku', $h, true) || in_array('name', $h, true))) {
            return 'woocommerce';
        }
        if (in_array('categories (x,y,z...)', $h, true) || (in_array('reference #', $h, true) && in_array('manufacturer', $h, true))) {
            return 'prestashop';
        }
        if (in_array('название_позиции', $h, true) || in_array('назва_позиції', $h, true) || in_array('код_товара', $h, true) || in_array('код_товару', $h, true)) {
            return 'prom';
        }
        foreach ($h as $name) {
            if (str_contains($name, 'horoshop') || str_contains($name, 'хорошоп')) {
                return 'horoshop';
            }
        }

        return 'generic';
    }

    public static function norm(string $header): string
    {
        $header = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $header)));

        return preg_replace('/\s+/u', ' ', $header) ?? $header;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @return array{products:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function normalize(string $platform, array $headers, array $rows): array
    {
        $index = [];
        foreach ($headers as $i => $header) {
            $key = self::norm($header);
            if ($key !== '') {
                $index[$key][] = $i;
            }
        }
        $records = [];
        foreach ($rows as $row) {
            if (count(array_filter($row, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $records[] = new class($row, $index) {
                /** @param list<string> $row @param array<string,list<int>> $index */
                public function __construct(public array $row, private array $index)
                {
                }

                /** @param list<string> $aliases */
                public function get(array $aliases): string
                {
                    foreach ($aliases as $alias) {
                        foreach ($this->index[$alias] ?? [] as $i) {
                            $v = trim((string) ($this->row[$i] ?? ''));
                            if ($v !== '') {
                                return $v;
                            }
                        }
                    }

                    return '';
                }

                /** @return list<string> */
                public function all(string $name): array
                {
                    $out = [];
                    foreach ($this->index[$name] ?? [] as $i) {
                        $out[] = trim((string) ($this->row[$i] ?? ''));
                    }

                    return $out;
                }
            };
        }

        return match ($platform) {
            'shopify' => self::shopify($records),
            'woocommerce' => self::woocommerce($records, $index),
            'prom' => self::prom($records, $index),
            default => self::flat($records, $platform),
        };
    }

    /** @param list<object> $records */
    private static function flat(array $records, string $platform): array
    {
        $products = [];
        $warnings = [];
        foreach ($records as $i => $r) {
            $p = self::common($r, $platform);
            $p['key'] = $p['sku'] !== '' ? $p['sku'] : ($p['slug'] !== '' ? $p['slug'] : 'row-' . ($i + 2));
            if ($platform === 'prestashop') {
                $p['categories'] = self::prestashopCategories($r->get(self::GENERIC['category']));
                $p['images'] = self::urls($r->get(self::GENERIC['images']));
                foreach (self::prestashopFeatures($r->get(['feature(name:value:position:customized)'])) as $name => $value) {
                    $p['attributes'][$name] = $value;
                }
            }
            $products[] = $p;
        }

        return ['products' => self::uniqueKeys($products), 'warnings' => $warnings];
    }

    /** @param list<object> $records */
    private static function shopify(array $records): array
    {
        $products = [];
        $warnings = [];
        $byHandle = [];
        foreach ($records as $r) {
            $handle = $r->get(['handle']);
            if ($handle === '') {
                continue;
            }
            if (!isset($byHandle[$handle])) {
                $p = self::common($r, 'shopify');
                $p['slug'] = $handle;
                $p['key'] = $handle;
                $p['legacy_paths'] = ['/products/' . $handle];
                $p['name'] = $r->get(['title']);
                $p['description'] = $r->get(['body (html)']);
                $p['brand'] = $r->get(['vendor']);
                $p['enabled'] = strtolower($r->get(['status'])) !== 'draft' && strtolower($r->get(['status'])) !== 'archived' && strtolower($r->get(['published'])) !== 'false';
                $cat = $r->get(['product category']) ?: $r->get(['type']);
                $p['categories'] = $cat === '' ? [] : [array_values(array_filter(array_map('trim', explode('>', $cat))))];
                $p['stock'] = 0;
                $p['option_names'] = [];
                for ($n = 1; $n <= 3; $n++) {
                    $name = $r->get(['option' . $n . ' name']);
                    if ($name !== '' && strtolower($name) !== 'title') {
                        $p['option_names'][$n] = $name;
                    }
                }
                $p['options'] = [];
                $p['variant_count'] = 0;
                $byHandle[$handle] = $p;
            }
            $p = &$byHandle[$handle];
            $image = $r->get(['image src']);
            if ($image !== '' && !in_array($image, $p['images'], true)) {
                $p['images'][] = $image;
            }
            $sku = $r->get(['variant sku']);
            $price = $r->get(['variant price']);
            if ($sku !== '' || $price !== '') {
                if ($p['variant_count'] === 0) {
                    $p['sku'] = $sku !== '' ? $sku : $handle;
                    $p['price'] = self::money($price);
                    $p['old_price'] = self::money($r->get(['variant compare at price']));
                    $p['gtin'] = $r->get(['variant barcode']);
                    $p['weight'] = ($g = (float) $r->get(['variant grams'])) > 0 ? number_format($g / 1000, 3, '.', '') : '';
                }
                ++$p['variant_count'];
                $p['stock'] += max(0, (int) $r->get(['variant inventory qty']));
                foreach ($p['option_names'] as $n => $name) {
                    $value = $r->get(['option' . $n . ' value']);
                    if ($value !== '' && !in_array($value, $p['options'][$name] ?? [], true)) {
                        $p['options'][$name][] = $value;
                    }
                }
            }
            unset($p);
        }
        foreach ($byHandle as $p) {
            if ($p['variant_count'] > 1) {
                $warnings[] = 'variants_flattened:' . $p['key'];
            }
            unset($p['option_names'], $p['variant_count']);
            $products[] = $p;
        }

        return ['products' => self::uniqueKeys($products), 'warnings' => $warnings];
    }

    /**
     * @param list<object> $records
     * @param array<string,list<int>> $index
     */
    private static function woocommerce(array $records, array $index): array
    {
        $products = [];
        $warnings = [];
        foreach ($records as $i => $r) {
            $type = strtolower($r->get(['type']));
            if ($type === 'variation') {
                $warnings[] = 'variation_skipped:' . ($r->get(['sku']) ?: (string) ($i + 2));
                continue;
            }
            $p = self::common($r, 'woocommerce');
            $sale = self::money($r->get(['sale price']));
            if ($sale !== '' && (float) $sale > 0 && (float) $sale < (float) $p['price']) {
                $p['old_price'] = $p['price'];
                $p['price'] = $sale;
            }
            $categories = [];
            foreach (explode(',', $r->get(['categories'])) as $path) {
                $parts = array_values(array_filter(array_map('trim', explode('>', $path))));
                if ($parts !== []) {
                    $categories[] = $parts;
                }
            }
            $p['categories'] = $categories;
            $p['images'] = self::urls($r->get(['images']));
            $stockText = strtolower($r->get(['in stock?']));
            $p['stock'] = $r->get(['stock']) !== '' ? (int) $r->get(['stock']) : ($stockText === '1' || $stockText === 'yes' ? 100 : 0);
            for ($n = 1; $n <= 20; $n++) {
                $name = $r->get(['attribute ' . $n . ' name']);
                if ($name === '') {
                    if (!isset($index['attribute ' . ($n + 1) . ' name'])) {
                        break;
                    }
                    continue;
                }
                $value = $r->get(['attribute ' . $n . ' value(s)']);
                if ($value !== '') {
                    $p['attributes'][$name] = implode(', ', array_filter(array_map('trim', explode('|', $value))));
                }
            }
            $p['key'] = $p['sku'] !== '' ? $p['sku'] : 'row-' . ($i + 2);
            $products[] = $p;
        }

        return ['products' => self::uniqueKeys($products), 'warnings' => $warnings];
    }

    /**
     * @param list<object> $records
     * @param array<string,list<int>> $index
     */
    private static function prom(array $records, array $index): array
    {
        $products = [];
        foreach ($records as $i => $r) {
            $p = self::common($r, 'prom');
            $availability = $r->get(['наличие', 'наявність']);
            $stock = $r->get(['количество', 'кількість']);
            $p['stock'] = $stock !== '' ? (int) $stock : (in_array($availability, ['+', '&'], true) ? 99 : 0);
            $group = $r->get(['название_группы', 'назва_групи']);
            $p['categories'] = $group === '' ? [] : [[$group]];
            $p['images'] = self::urls($r->get(['ссылка_изображения', 'посилання_зображення']));
            $names = $r->all('название_характеристики') ?: $r->all('назва_характеристики');
            $values = $r->all('значение_характеристики') ?: $r->all('значення_характеристики');
            foreach ($names as $k => $name) {
                if ($name !== '' && ($values[$k] ?? '') !== '') {
                    $p['attributes'][$name] = $values[$k];
                }
            }
            $keywords = $r->get(['ключевые_слова', 'пошукові_запити']);
            if ($keywords !== '' && $p['meta_description'] === '') {
                $p['meta_description'] = mb_substr($keywords, 0, 255);
            }
            $p['enabled'] = $availability !== '-' || $p['stock'] > 0;
            $p['key'] = $r->get(['уникальный_идентификатор', 'унікальний_ідентифікатор', 'идентификатор_товара', 'ідентифікатор_товару']) ?: ($p['sku'] !== '' ? $p['sku'] : 'row-' . ($i + 2));
            $products[] = $p;
        }

        return ['products' => self::uniqueKeys($products), 'warnings' => []];
    }

    /** @return array<string,mixed> */
    private static function common(object $r, string $platform): array
    {
        $g = self::GENERIC;
        $enabledRaw = strtolower($r->get($g['enabled']));
        $stock = $r->get($g['stock']);
        $availability = $r->get($g['availability']);
        $category = $r->get($g['category']);

        return [
            'key' => '', 'sku' => $r->get($g['sku']), 'name' => $r->get($g['name']), 'name_ru' => $r->get($g['name_ru']), 'description' => $r->get($g['description']),
            'description_ru' => $r->get($g['description_ru']), 'short_description' => $r->get($g['short_description']),
            'price' => self::money($r->get($g['price'])), 'old_price' => self::money($r->get($g['old_price'])),
            'stock' => $stock !== '' ? (int) preg_replace('/[^\d-]/', '', $stock) : (in_array(mb_strtolower($availability), ['+', 'так', 'в наявності', 'в наличии', 'in stock', 'yes', '1', 'true'], true) ? 99 : null),
            'enabled' => !in_array($enabledRaw, ['0', 'false', 'no', 'ні', 'нет', 'draft', 'inactive', 'disabled', 'hidden', 'не відображати'], true),
            'weight' => $r->get($g['weight']), 'gtin' => $r->get($g['gtin']), 'mpn' => $r->get($g['mpn']), 'brand' => $r->get($g['brand']),
            'categories' => $category === '' ? [] : array_map(static fn (string $path): array => array_values(array_filter(array_map('trim', preg_split('/\s*[>\/]\s*/u', $path) ?: []))), array_values(array_filter(array_map('trim', explode(';', $category))))),
            'images' => self::urls($r->get($g['images'])), 'slug' => self::slug($r->get($g['slug'])), 'legacy_paths' => [],
            'meta_title' => $r->get($g['meta_title']), 'meta_description' => $r->get($g['meta_description']), 'attributes' => [], 'options' => [], 'platform' => $platform,
        ];
    }

    /** @return list<list<string>> */
    private static function prestashopCategories(string $value): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $name) {
            if (!ctype_digit($name)) {
                $out[] = [$name];
            }
        }

        return $out;
    }

    /** @return array<string,string> "Name:Value:Position:Custom,Name2:Value2" */
    private static function prestashopFeatures(string $value): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $feature) {
            $parts = explode(':', $feature);
            if (count($parts) >= 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                $out[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function urls(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,;|]+/', $value) ?: [] as $url) {
            $url = trim($url);
            if (preg_match('~^https?://[^\s]+$~i', $url) === 1 && !in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    private static function slug(string $value): string
    {
        $value = trim($value);
        $path = parse_url($value, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $value = $path;
        }
        $value = trim(preg_replace('~\.(html?|php)$~i', '', trim($value, '/')) ?? $value, '/');

        return preg_match('~^[\p{L}\p{N}][\p{L}\p{N}/_.-]{0,190}$~u', $value) === 1 ? $value : '';
    }

    /** A decimal string with a dot from "1 234,50 грн", "1,234.50", "99" or "". */
    public static function money(string $value): string
    {
        $value = preg_replace('/[^\d.,]/', '', str_replace("\xC2\xA0", '', $value)) ?? '';
        if ($value === '') {
            return '';
        }
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $value = $comma > $dot ? str_replace('.', '', $value) : str_replace(',', '', $value);
        }
        $value = str_replace(',', '.', $value);
        if (substr_count($value, '.') > 1) {
            $last = strrpos($value, '.');
            $value = str_replace('.', '', substr($value, 0, (int) $last)) . substr($value, (int) $last);
        }

        return is_numeric($value) ? $value : '';
    }

    /**
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    private static function uniqueKeys(array $products): array
    {
        $seen = [];
        foreach ($products as $i => $p) {
            $key = (string) $p['key'];
            if ($key === '' || isset($seen[$key])) {
                $key = ($key === '' ? 'row' : $key) . '-' . ($i + 2);
            }
            $seen[$key] = true;
            $products[$i]['key'] = $key;
        }

        return $products;
    }
}
