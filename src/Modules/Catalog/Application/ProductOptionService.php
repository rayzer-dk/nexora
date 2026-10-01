<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;

/**
 * Options of one product (Colour: red, black; Memory: 128, 256): each value may change the price and show a product picture.
 * A purchasable combination is a variant; "generate" creates the missing combinations, so stock, SKU and price stay per variant.
 */
final readonly class ProductOptionService
{
    private const MAX_COMBINATIONS = 200;

    public function __construct(
        private Connection $db,
        private PublicIdFactory $publicIds,
        private ProductVariantService $variants,
    ) {
    }

    /** @return list<array{id:int,name:string,values:list<array{id:int,label:string,swatch:string,delta:string,media_id:?int}>}> */
    public function forEdit(int $productId, string $locale): array
    {
        $result = [];
        foreach ($this->db->fetchAllAssociative('SELECT po.id,COALESCE(pot.name,po.code) name FROM mc_product_option po LEFT JOIN mc_product_option_translation pot ON pot.option_id=po.id AND pot.locale=? WHERE po.product_id=? ORDER BY po.sort_order,po.id', [$locale, $productId]) as $option) {
            $values = [];
            foreach ($this->db->fetchAllAssociative('SELECT ov.id,ov.swatch,ov.price_delta_minor,ov.media_asset_id,COALESCE(ovt.name,ov.code) label FROM mc_product_option_value ov LEFT JOIN mc_product_option_value_translation ovt ON ovt.option_value_id=ov.id AND ovt.locale=? WHERE ov.option_id=? ORDER BY ov.sort_order,ov.id', [$locale, (int) $option['id']]) as $value) {
                $values[] = ['id' => (int) $value['id'], 'label' => (string) $value['label'], 'swatch' => (string) ($value['swatch'] ?? ''), 'delta' => number_format(((int) $value['price_delta_minor']) / 100, 2, '.', ''), 'media_id' => $value['media_asset_id'] !== null ? (int) $value['media_asset_id'] : null];
            }
            $result[] = ['id' => (int) $option['id'], 'name' => (string) $option['name'], 'values' => $values];
        }

        return $result;
    }

    /** Option text of every variant: [variantId => 'Black / 128']. @return array<int,string> */
    public function variantLabels(int $productId, string $locale): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT vov.variant_id,COALESCE(ovt.name,ov.code) label FROM mc_variant_option_value vov JOIN mc_product_variant v ON v.id=vov.variant_id JOIN mc_product_option_value ov ON ov.id=vov.option_value_id JOIN mc_product_option po ON po.id=ov.option_id LEFT JOIN mc_product_option_value_translation ovt ON ovt.option_value_id=ov.id AND ovt.locale=? WHERE v.product_id=? ORDER BY po.sort_order,ov.sort_order", [$locale, $productId]);
        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row['variant_id']][] = (string) $row['label'];
        }

        return array_map(static fn (array $parts): string => implode(' / ', $parts), $labels);
    }

    /** Pictures of the product that an option value can show. @return list<array{id:int,label:string}> */
    public function pictures(int $productId): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT ma.id,COALESCE(NULLIF(pm.alt_text,''),ma.storage_key) label FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=? AND pm.role IN ('primary','gallery') ORDER BY (pm.role='primary') DESC,pm.sort_order", [$productId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => basename((string) $r['label'])], $rows);
    }

    /**
     * Saves the edited names, values, price differences, swatches and pictures, then adds a new option and new values when sent.
     *
     * @param array<int|string,mixed> $options  option id => ['name'=>…, 'new_value'=>…]
     * @param array<int|string,mixed> $values   value id  => ['label'=>…, 'delta'=>…, 'swatch'=>…, 'media'=>…]
     * @param array<string,mixed>     $newOption ['name'=>…, 'values'=>'a, b, c']
     */
    public function save(int $productId, string $locale, array $options, array $values, array $newOption): void
    {
        $this->db->transactional(function (Connection $db) use ($productId, $locale, $options, $values, $newOption): void {
            $own = array_map('intval', $db->fetchFirstColumn('SELECT id FROM mc_product_option WHERE product_id=?', [$productId]));
            foreach ($options as $optionId => $data) {
                if (!in_array((int) $optionId, $own, true) || !is_array($data)) {
                    continue;
                }
                $name = $this->text((string) ($data['name'] ?? ''), 190);
                if ($name !== '') {
                    $this->translate($db, 'mc_product_option_translation', 'option_id', (int) $optionId, $locale, $name);
                }
                foreach ($this->splitValues((string) ($data['new_value'] ?? '')) as $label) {
                    $this->insertValue($db, (int) $optionId, $locale, $label);
                }
            }
            $ownValues = array_map('intval', $db->fetchFirstColumn('SELECT ov.id FROM mc_product_option_value ov JOIN mc_product_option po ON po.id=ov.option_id WHERE po.product_id=?', [$productId]));
            $pictures = array_column($this->pictures($productId), 'id');
            foreach ($values as $valueId => $data) {
                if (!in_array((int) $valueId, $ownValues, true) || !is_array($data)) {
                    continue;
                }
                $label = $this->text((string) ($data['label'] ?? ''), 190);
                if ($label !== '') {
                    $this->translate($db, 'mc_product_option_value_translation', 'option_value_id', (int) $valueId, $locale, $label);
                }
                $swatch = strtolower(trim((string) ($data['swatch'] ?? '')));
                $media = (int) ($data['media'] ?? 0);
                $db->update('mc_product_option_value', [
                    'price_delta_minor' => $this->minor((string) ($data['delta'] ?? '0')),
                    'swatch' => preg_match('/^#[0-9a-f]{6}$/D', $swatch) === 1 ? $swatch : null,
                    'media_asset_id' => in_array($media, $pictures, true) ? $media : null,
                ], ['id' => (int) $valueId]);
            }
            $name = $this->text((string) ($newOption['name'] ?? ''), 190);
            if ($name !== '') {
                $order = (int) $db->fetchOne('SELECT COALESCE(MAX(sort_order),0)+1 FROM mc_product_option WHERE product_id=?', [$productId]);
                $db->insert('mc_product_option', ['public_id' => $this->publicIds->binary(), 'product_id' => $productId, 'code' => 'o' . bin2hex(random_bytes(5)), 'sort_order' => $order]);
                $optionId = (int) $db->lastInsertId();
                $this->translate($db, 'mc_product_option_translation', 'option_id', $optionId, $locale, $name);
                foreach ($this->splitValues((string) ($newOption['values'] ?? '')) as $label) {
                    $this->insertValue($db, $optionId, $locale, $label);
                }
            }
        });
    }

    public function deleteOption(int $productId, int $optionId): void
    {
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_option WHERE id=? AND product_id=?', [$optionId, $productId]) !== 1) {
            return;
        }
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_variant_option_value vov JOIN mc_product_option_value ov ON ov.id=vov.option_value_id WHERE ov.option_id=?', [$optionId]) > 0) {
            throw new \DomainException(CanonicalUiText::get('admin.catalog.options.error_in_use'));
        }
        $this->db->delete('mc_product_option', ['id' => $optionId]);
    }

    public function deleteValue(int $productId, int $valueId): void
    {
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_option_value ov JOIN mc_product_option po ON po.id=ov.option_id WHERE ov.id=? AND po.product_id=?', [$valueId, $productId]) !== 1) {
            return;
        }
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_variant_option_value WHERE option_value_id=?', [$valueId]) > 0) {
            throw new \DomainException(CanonicalUiText::get('admin.catalog.options.error_in_use'));
        }
        $this->db->delete('mc_product_option_value', ['id' => $valueId]);
    }

    /**
     * Creates the variants for every combination of option values that does not exist yet. The main variant takes the first
     * combination; a new variant costs the main price minus its differences plus its own differences.
     */
    public function generate(int $productId, int $storeId, int $marketId): int
    {
        $groups = [];
        foreach ($this->db->fetchAllAssociative('SELECT id FROM mc_product_option WHERE product_id=? ORDER BY sort_order,id', [$productId]) as $option) {
            $ids = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM mc_product_option_value WHERE option_id=? ORDER BY sort_order,id', [(int) $option['id']]));
            if ($ids !== []) {
                $groups[] = $ids;
            }
        }
        if ($groups === []) {
            throw new \DomainException(CanonicalUiText::get('admin.catalog.options.error_empty'));
        }
        $combinations = [[]];
        foreach ($groups as $ids) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($ids as $id) {
                    $next[] = [...$combination, $id];
                }
            }
            $combinations = $next;
            if (count($combinations) > self::MAX_COMBINATIONS) {
                throw new \DomainException(CanonicalUiText::get('admin.catalog.options.error_too_many'));
            }
        }

        $main = $this->db->fetchAssociative('SELECT id,sku,sale_unit_code FROM mc_product_variant WHERE product_id=? ORDER BY sort_order,id LIMIT 1', [$productId]);
        if (!is_array($main)) {
            throw new \DomainException(CanonicalUiText::get('admin.catalog.options.error_empty'));
        }
        $deltas = [];
        foreach ($this->db->fetchAllAssociative('SELECT ov.id,ov.price_delta_minor FROM mc_product_option_value ov JOIN mc_product_option po ON po.id=ov.option_id WHERE po.product_id=?', [$productId]) as $row) {
            $deltas[(int) $row['id']] = (int) $row['price_delta_minor'];
        }
        $existing = [];
        foreach ($this->db->fetchAllAssociative('SELECT vov.variant_id,vov.option_value_id FROM mc_variant_option_value vov JOIN mc_product_variant v ON v.id=vov.variant_id WHERE v.product_id=?', [$productId]) as $row) {
            $existing[(int) $row['variant_id']][] = (int) $row['option_value_id'];
        }
        $known = [];
        foreach ($existing as $variantId => $ids) {
            sort($ids);
            $known[implode(',', $ids)] = $variantId;
        }
        $mainId = (int) $main['id'];
        $mainValues = $existing[$mainId] ?? [];
        $mainPrice = (int) $this->db->fetchOne("SELECT amount_minor FROM mc_price WHERE variant_id=? AND store_id=? AND customer_group='default' AND price_list_id IS NULL ORDER BY (market_id=?) DESC,min_quantity,id LIMIT 1", [$mainId, $storeId, $marketId]);
        $base = $mainPrice - array_sum(array_map(static fn (int $id): int => $deltas[$id] ?? 0, $mainValues));
        $created = 0;
        $suffix = 0;
        foreach ($combinations as $combination) {
            $ids = $combination;
            sort($ids);
            if (isset($known[implode(',', $ids)])) {
                continue;
            }
            if ($mainValues === []) {
                foreach ($combination as $valueId) {
                    $this->db->insert('mc_variant_option_value', ['variant_id' => $mainId, 'option_value_id' => $valueId]);
                }
                $mainValues = $combination;
                $created++;
                continue;
            }
            do {
                $suffix++;
                $sku = $main['sku'] . '-' . $suffix;
            } while ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_variant WHERE sku=?', [$sku]) > 0);
            $price = max(0, $base + array_sum(array_map(static fn (int $id): int => $deltas[$id] ?? 0, $combination)));
            $variant = $this->variants->create($productId, $storeId, $marketId, $sku, $price, '0', (string) $main['sale_unit_code']);
            foreach ($combination as $valueId) {
                $this->db->insert('mc_variant_option_value', ['variant_id' => $variant['id'], 'option_value_id' => $valueId]);
            }
            $created++;
        }

        return $created;
    }

    /** @return list<string> */
    private function splitValues(string $csv): array
    {
        $labels = [];
        foreach (preg_split('/[,;\n]+/u', $csv) ?: [] as $part) {
            $label = $this->text($part, 190);
            if ($label !== '' && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return array_slice($labels, 0, 60);
    }

    private function insertValue(Connection $db, int $optionId, string $locale, string $label): void
    {
        $order = (int) $db->fetchOne('SELECT COALESCE(MAX(sort_order),0)+1 FROM mc_product_option_value WHERE option_id=?', [$optionId]);
        $db->insert('mc_product_option_value', ['public_id' => $this->publicIds->binary(), 'option_id' => $optionId, 'code' => 'v' . bin2hex(random_bytes(5)), 'sort_order' => $order]);
        $this->translate($db, 'mc_product_option_value_translation', 'option_value_id', (int) $db->lastInsertId(), $locale, $label);
    }

    private function translate(Connection $db, string $table, string $column, int $id, string $locale, string $name): void
    {
        $db->executeStatement("INSERT INTO {$table} ({$column},locale,name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)", [$id, $locale, $name]);
    }

    private function text(string $value, int $max): string
    {
        return mb_substr(trim(strip_tags($value)), 0, $max);
    }

    private function minor(string $value): int
    {
        $clean = str_replace([' ', ','], ['', '.'], trim($value));

        return is_numeric($clean) ? (int) round(((float) $clean) * 100) : 0;
    }
}
