<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Source\File;

use Commerce\Modules\Migration\Contract\MigrationSourceInterface;
use Commerce\Modules\Migration\Domain\MigrationBatch;
use Commerce\Modules\Migration\Domain\MigrationEntityType;
use Commerce\Modules\Migration\Domain\MigrationRecord;

/**
 * A catalogue read from the product file of another platform (see PlatformCatalogProfiles): brands, a category tree, characteristics and products
 * in the shape the migration importer already takes from OpenCart. Every text is in one source language, named by $locale.
 */
final class FileCatalogSource implements MigrationSourceInterface
{
    /** @var list<MigrationRecord> */
    private array $brands = [];
    /** @var list<MigrationRecord> */
    private array $categories = [];
    /** @var list<MigrationRecord> */
    private array $attributes = [];
    /** @var list<MigrationRecord> */
    private array $products = [];

    /**
     * @param list<array<string,mixed>> $normalized products of PlatformCatalogProfiles::normalize()
     * @param string $locale the language of the texts in the file (a locale code of this shop, such as uk-UA)
     * @param string $fileKey a short key of the file, so two files do not share ids
     */
    public function __construct(private readonly string $platform, array $normalized, private readonly string $locale, private readonly string $fileKey)
    {
        $this->build($normalized);
    }

    public function code(): string
    {
        return 'file-' . $this->platform;
    }

    public function label(): string
    {
        return 'File: ' . $this->platform . ' (' . $this->fileKey . ')';
    }

    public function supportedEntities(): array
    {
        return [MigrationEntityType::Brand, MigrationEntityType::Category, MigrationEntityType::Attribute, MigrationEntityType::Product];
    }

    public function read(MigrationEntityType $type, ?string $cursor, int $limit = 250): MigrationBatch
    {
        $all = match ($type) {
            MigrationEntityType::Brand => $this->brands,
            MigrationEntityType::Category => $this->categories,
            MigrationEntityType::Attribute => $this->attributes,
            MigrationEntityType::Product => $this->products,
            default => [],
        };
        $offset = max(0, (int) $cursor);
        $slice = array_slice($all, $offset, $limit);
        $next = $offset + count($slice);

        return new MigrationBatch($slice, $next < count($all) ? (string) $next : null, $next >= count($all));
    }

    /** @param list<array<string,mixed>> $normalized */
    private function build(array $normalized): void
    {
        $brandKeys = [];
        $categoryKeys = [];
        $attributeKeys = [];
        foreach ($normalized as $p) {
            $brandKey = null;
            $brand = trim((string) ($p['brand'] ?? ''));
            if ($brand !== '') {
                $brandKey = 'b-' . substr(sha1(mb_strtolower($brand)), 0, 16);
                if (!isset($brandKeys[$brandKey])) {
                    $brandKeys[$brandKey] = true;
                    $this->brands[] = new MigrationRecord(MigrationEntityType::Brand, $brandKey, ['name' => $brand, 'image' => null, 'sort_order' => 0, 'seo_urls' => []]);
                }
            }
            $leafKeys = [];
            foreach ((array) ($p['categories'] ?? []) as $path) {
                $parentKey = null;
                $trail = [];
                foreach (array_slice(array_values((array) $path), 0, 6) as $name) {
                    $name = mb_substr(trim((string) $name), 0, 190);
                    if ($name === '') {
                        continue;
                    }
                    $trail[] = mb_strtolower($name);
                    $key = 'c-' . substr(sha1(implode('/', $trail)), 0, 16);
                    if (!isset($categoryKeys[$key])) {
                        $categoryKeys[$key] = true;
                        $this->categories[] = new MigrationRecord(MigrationEntityType::Category, $key, [
                            'parent_source_key' => $parentKey, 'image' => null, 'sort_order' => count($this->categories), 'enabled' => true,
                            'translations' => [$this->locale => ['name' => $name, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '']], 'seo_urls' => [],
                        ]);
                    }
                    $parentKey = $key;
                }
                if ($parentKey !== null) {
                    $leafKeys[$parentKey] = true;
                }
            }
            $attributeValues = [];
            foreach ((array) ($p['attributes'] ?? []) as $name => $value) {
                $name = mb_substr(trim((string) $name), 0, 190);
                $value = trim((string) $value);
                if ($name === '' || $value === '') {
                    continue;
                }
                $key = 'a-' . substr(sha1(mb_strtolower($name)), 0, 16);
                if (!isset($attributeKeys[$key])) {
                    $attributeKeys[$key] = true;
                    $this->attributes[] = new MigrationRecord(MigrationEntityType::Attribute, $key, ['group_source_key' => '', 'sort_order' => count($this->attributes), 'translations' => [$this->locale => $name]]);
                }
                $attributeValues[$key] = [$this->locale => $value];
            }
            $options = [];
            $optionIndex = 0;
            foreach ((array) ($p['options'] ?? []) as $name => $values) {
                $values = array_values(array_filter(array_map('trim', (array) $values), static fn (string $v): bool => $v !== ''));
                if ($values === []) {
                    continue;
                }
                $optionKey = 'o-' . substr(sha1(mb_strtolower((string) $name)), 0, 12);
                $locale = $this->locale;
                $options[] = [
                    'option_source_key' => $optionKey, 'translations' => [$this->locale => mb_substr((string) $name, 0, 190)],
                    'values' => array_map(static fn (string $v, int $i): array => ['source_key' => $optionKey . '-' . substr(sha1(mb_strtolower($v)), 0, 10), 'sort_order' => $i, 'translations' => [$locale => mb_substr($v, 0, 190)]], $values, array_keys($values)),
                ];
                ++$optionIndex;
            }
            $texts = ['name' => (string) $p['name'], 'description' => (string) $p['description'], 'short_description' => (string) ($p['short_description'] ?? ''), 'meta_title' => (string) ($p['meta_title'] ?? ''), 'meta_description' => (string) ($p['meta_description'] ?? ''), 'meta_keywords' => ''];
            $translations = [$this->locale => $texts];
            $price = (string) ($p['price'] ?? '');
            $old = (string) ($p['old_price'] ?? '');
            $slug = (string) ($p['slug'] ?? '');
            $this->products[] = new MigrationRecord(MigrationEntityType::Product, (string) $p['key'], [
                'sku' => (string) $p['sku'], 'model' => '', 'gtin' => ($p['gtin'] ?? '') !== '' ? (string) $p['gtin'] : null, 'mpn' => ($p['mpn'] ?? '') !== '' ? (string) $p['mpn'] : null,
                'brand_source_key' => $brandKey, 'brand_name' => $brand !== '' ? $brand : null,
                'quantity' => max(0, (int) ($p['stock'] ?? 0)),
                'legacy_price_decimal' => $price !== '' ? $price : ($old !== '' ? $old : '0'),
                'compare_at_decimal' => $old !== '' && $price !== '' && (float) $old > (float) $price ? $old : null,
                'weight' => (string) ($p['weight'] ?? ''), 'enabled' => (bool) ($p['enabled'] ?? true), 'sort_order' => 0, 'main_image' => null, 'images' => [],
                'image_urls' => array_slice(array_values((array) ($p['images'] ?? [])), 0, 12),
                'category_source_keys' => array_keys($leafKeys), 'translations' => $translations, 'attributes' => $attributeValues, 'options' => $options,
                'specials' => [], 'discounts' => [],
                'seo_urls' => $slug !== '' ? [['language_code' => $this->locale, 'keyword' => $slug]] : [],
                'legacy_paths' => array_values(array_filter(array_map('strval', (array) ($p['legacy_paths'] ?? [])))),
            ]);
        }
    }
}
