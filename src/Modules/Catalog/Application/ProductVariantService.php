<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Mutates non-default product variants without putting the base product editor at risk.
 * The default variant (sort_order=0) remains owned by ProductWriter so a failed
 * optional variant edit can never make the core product form unusable.
 */
final readonly class ProductVariantService
{
    public function __construct(
        private Connection $db,
        private PublicIdFactory $publicIds,
    ) {
    }

    /** @return array{id:int,public_id:string} */
    public function create(
        int $productId,
        int $storeId,
        int $marketId,
        string $sku,
        int $priceMinor,
        string $stockQuantity,
        string $unitCode,
        ?string $gtin = null,
        ?string $mpn = null,
        bool $allowBackorder = false,
        ?float $weightKg = null,
        ?int $lengthMm = null,
        ?int $widthMm = null,
        ?int $heightMm = null,
    ): array {
        $sku = $this->sku($sku);
        $stockQuantity = $this->quantity($stockQuantity);
        $gtin = $this->nullableText($gtin, 32);
        $mpn = $this->nullableText($mpn, 190);
        $this->dimensions($weightKg, $lengthMm, $widthMm, $heightMm);
        if ($priceMinor < 0 || $priceMinor > 999_999_999_999) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.tsina_varianta_nekorektna'));
        }

        return $this->db->transactional(function (Connection $db) use ($productId, $storeId, $marketId, $sku, $priceMinor, $stockQuantity, $unitCode, $gtin, $mpn, $allowBackorder, $weightKg, $lengthMm, $widthMm, $heightMm): array {
            $product = $db->fetchAssociative(
                'SELECT p.id,p.product_type FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.id=? FOR UPDATE',
                [$storeId, $productId],
            );
            if (!is_array($product)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.tovar_ne_znaideno_v_potochnomu_mahazyni'));
            }
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_market WHERE id=? AND store_id=? AND status=?', [$marketId, $storeId, 'active']) !== 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.rynok_ne_nalezhyt_potochnomu_mahazynu'));
            }
            $currency = $db->fetchOne('SELECT default_currency FROM mc_market WHERE id=? LIMIT 1', [$marketId]);
            if (!is_string($currency) || $currency === '') {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.dlia_rynku_ne_vyznacheno_valiutu'));
            }
            $step = $this->unitStep($db, $unitCode);
            $this->assertSkuAvailable($db, $sku, null);
            $this->assertGtinAvailable($db, $gtin, null);

            $maxSort = (int) ($db->fetchOne('SELECT COALESCE(MAX(sort_order),0) FROM mc_product_variant WHERE product_id=?', [$productId]) ?: 0);
            $uuid = $this->publicIds->generate();
            $now = $this->now();
            $isPhysical = (string) $product['product_type'] === 'physical';
            $db->insert('mc_product_variant', [
                'public_id' => $uuid->toBinary(),
                'product_id' => $productId,
                'sku' => $sku,
                'gtin' => $gtin,
                'mpn' => $mpn,
                'status' => 'active',
                'manage_inventory' => $isPhysical ? 1 : 0,
                'allow_backorder' => $allowBackorder ? 1 : 0,
                'sort_order' => max(10, ((int) floor($maxSort / 10) + 1) * 10),
                'sale_unit_code' => $unitCode,
                'quantity_step' => $step,
                'min_order_quantity' => $step,
                'max_order_quantity' => null,
                'unit_pricing_measure_value' => null,
                'unit_pricing_measure_code' => null,
                'unit_pricing_base_value' => null,
                'unit_pricing_base_code' => null,
                'weight_kg' => $weightKg,
                'length_mm' => $lengthMm,
                'width_mm' => $widthMm,
                'height_mm' => $heightMm,
                'shipping_class' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'row_version' => 1,
            ]);
            $variantId = (int) $db->lastInsertId();

            $db->insert('mc_price', [
                'variant_id' => $variantId,
                'store_id' => $storeId,
                'price_list_id' => null,
                'market_id' => $marketId,
                'currency' => strtoupper($currency),
                'customer_group' => 'default',
                'min_quantity' => $step,
                'max_quantity' => null,
                'amount_minor' => $priceMinor,
                'compare_at_minor' => null,
                'tax_included' => 1,
                'priority' => 100,
                'starts_at' => null,
                'ends_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($isPhysical) {
                $locationId = $this->primaryLocation($db, $marketId);
                $inventoryUuid = $this->publicIds->generate();
                $db->insert('mc_inventory_item', [
                    'public_id' => $inventoryUuid->toBinary(),
                    'sku' => $sku,
                    'unit_code' => $unitCode,
                    'requires_shipping' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inventoryItemId = (int) $db->lastInsertId();
                $db->insert('mc_variant_inventory_item', [
                    'variant_id' => $variantId,
                    'inventory_item_id' => $inventoryItemId,
                    'required_quantity' => '1.000000',
                ]);
                $db->insert('mc_stock_level', [
                    'inventory_item_id' => $inventoryItemId,
                    'location_id' => $locationId,
                    'stocked_quantity' => $stockQuantity,
                    'reserved_quantity' => '0.000000',
                    'incoming_quantity' => '0.000000',
                    'safety_stock' => '0.000000',
                    'row_version' => 1,
                    'updated_at' => $now,
                ]);
            }

            return ['id' => $variantId, 'public_id' => $uuid->toRfc4122()];
        });
    }

    public function update(
        int $productId,
        int $variantId,
        int $storeId,
        int $marketId,
        string $sku,
        int $priceMinor,
        string $stockQuantity,
        string $unitCode,
        string $status,
        ?string $gtin = null,
        ?string $mpn = null,
        bool $allowBackorder = false,
        ?float $weightKg = null,
        ?int $lengthMm = null,
        ?int $widthMm = null,
        ?int $heightMm = null,
    ): void {
        $sku = $this->sku($sku);
        $stockQuantity = $this->quantity($stockQuantity);
        $gtin = $this->nullableText($gtin, 32);
        $mpn = $this->nullableText($mpn, 190);
        $this->dimensions($weightKg, $lengthMm, $widthMm, $heightMm);
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.nevidomyi_status_varianta'));
        }
        if ($priceMinor < 0 || $priceMinor > 999_999_999_999) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.tsina_varianta_nekorektna'));
        }

        $this->db->transactional(function (Connection $db) use ($productId, $variantId, $storeId, $marketId, $sku, $priceMinor, $stockQuantity, $unitCode, $status, $gtin, $mpn, $allowBackorder, $weightKg, $lengthMm, $widthMm, $heightMm): void {
            $row = $db->fetchAssociative(
                'SELECT v.*,p.product_type FROM mc_product_variant v JOIN mc_product p ON p.id=v.product_id JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE v.id=? AND v.product_id=? FOR UPDATE',
                [$storeId, $variantId, $productId],
            );
            if (!is_array($row)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.variant_ne_znaideno'));
            }
            if ((int) $row['sort_order'] === 0) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.osnovnyi_variant_redahuietsia_u_blotsi_prodazh_shcho'));
            }
            $step = $this->unitStep($db, $unitCode);
            $this->assertSkuAvailable($db, $sku, $variantId);
            $this->assertGtinAvailable($db, $gtin, $variantId);
            $now = $this->now();
            $db->update('mc_product_variant', [
                'sku' => $sku,
                'gtin' => $gtin,
                'mpn' => $mpn,
                'status' => $status,
                'allow_backorder' => $allowBackorder ? 1 : 0,
                'sale_unit_code' => $unitCode,
                'quantity_step' => $step,
                'min_order_quantity' => $step,
                'weight_kg' => $weightKg,
                'length_mm' => $lengthMm,
                'width_mm' => $widthMm,
                'height_mm' => $heightMm,
                'updated_at' => $now,
                'row_version' => (int) $row['row_version'] + 1,
            ], ['id' => $variantId]);

            $priceId = $db->fetchOne(
                "SELECT id FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND max_quantity IS NULL AND starts_at IS NULL AND ends_at IS NULL ORDER BY priority ASC,id DESC LIMIT 1",
                [$variantId, $storeId, $marketId],
            );
            if ($priceId === false) {
                $currency = (string) $db->fetchOne('SELECT default_currency FROM mc_market WHERE id=? AND store_id=? LIMIT 1', [$marketId, $storeId]);
                if ($currency === '') {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.dlia_rynku_ne_vyznacheno_valiutu'));
                }
                $db->insert('mc_price', [
                    'variant_id' => $variantId, 'store_id' => $storeId, 'price_list_id' => null, 'market_id' => $marketId,
                    'currency' => strtoupper($currency), 'customer_group' => 'default', 'min_quantity' => $step, 'max_quantity' => null,
                    'amount_minor' => $priceMinor, 'compare_at_minor' => null, 'tax_included' => 1, 'priority' => 100,
                    'starts_at' => null, 'ends_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $db->update('mc_price', ['amount_minor' => $priceMinor, 'min_quantity' => $step, 'updated_at' => $now], ['id' => (int) $priceId]);
            }

            if ((string) $row['product_type'] === 'physical') {
                $inventoryItemId = $db->fetchOne('SELECT inventory_item_id FROM mc_variant_inventory_item WHERE variant_id=? ORDER BY inventory_item_id LIMIT 1', [$variantId]);
                if ($inventoryItemId === false) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.dlia_fizychnoho_varianta_vidsutnia_skladska_pozytsii'));
                }
                $inventoryItemId = (int) $inventoryItemId;
                $db->update('mc_inventory_item', ['sku' => $sku, 'unit_code' => $unitCode, 'updated_at' => $now], ['id' => $inventoryItemId]);
                $locationId = $this->primaryLocation($db, $marketId);
                $stock = $db->fetchAssociative('SELECT id,row_version FROM mc_stock_level WHERE inventory_item_id=? AND location_id=? FOR UPDATE', [$inventoryItemId, $locationId]);
                if (is_array($stock)) {
                    $db->update('mc_stock_level', ['stocked_quantity' => $stockQuantity, 'row_version' => (int) $stock['row_version'] + 1, 'updated_at' => $now], ['id' => (int) $stock['id']]);
                } else {
                    $db->insert('mc_stock_level', [
                        'inventory_item_id' => $inventoryItemId, 'location_id' => $locationId, 'stocked_quantity' => $stockQuantity,
                        'reserved_quantity' => '0.000000', 'incoming_quantity' => '0.000000', 'safety_stock' => '0.000000', 'row_version' => 1, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function delete(int $productId, int $variantId, int $storeId): void
    {
        $this->db->transactional(function (Connection $db) use ($productId, $variantId, $storeId): void {
            $variant = $db->fetchAssociative(
                'SELECT v.id,v.sort_order FROM mc_product_variant v JOIN mc_product p ON p.id=v.product_id JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE v.id=? AND v.product_id=? FOR UPDATE',
                [$storeId, $variantId, $productId],
            );
            if (!is_array($variant)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.variant_ne_znaideno'));
            }
            if ((int) $variant['sort_order'] === 0) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.osnovnyi_variant_tovaru_vydalyty_ne_mozhna'));
            }
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_product_variant WHERE product_id=?', [$productId]) <= 1) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.tovar_povynen_maty_shchonaimenshe_odyn_variant'));
            }
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_sales_order_item WHERE variant_id=?', [$variantId]) > 0) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.variant_ie_v_istorii_zamovlen_vymknit_ioho_zamist_vy'));
            }
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_cart_item WHERE variant_id=?', [$variantId]) > 0) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.variant_znakhodytsia_v_aktyvnomu_koshyku_vymknit_ioh'));
            }
            $inventoryIds = array_map('intval', $db->fetchFirstColumn('SELECT inventory_item_id FROM mc_variant_inventory_item WHERE variant_id=?', [$variantId]));
            foreach ($inventoryIds as $inventoryId) {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_inventory_reservation WHERE inventory_item_id=?', [$inventoryId]) > 0) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.variant_maie_istoriiu_rezervuvannia_vymknit_ioho_zam'));
                }
            }
            $db->delete('mc_product_variant', ['id' => $variantId]);
            foreach ($inventoryIds as $inventoryId) {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_variant_inventory_item WHERE inventory_item_id=?', [$inventoryId]) === 0) {
                    $db->delete('mc_inventory_item', ['id' => $inventoryId]);
                }
            }
        });
    }

    private function assertSkuAvailable(Connection $db, string $sku, ?int $exceptVariantId): void
    {
        $params = [$sku];
        $sql = 'SELECT COUNT(*) FROM mc_product_variant WHERE sku=?';
        if ($exceptVariantId !== null) {
            $sql .= ' AND id<>?';
            $params[] = $exceptVariantId;
        }
        if ((int) $db->fetchOne($sql, $params) > 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.sku_vzhe_vykorystovuietsia_inshym_variantom'));
        }
        $inventoryId = $exceptVariantId === null ? false : $db->fetchOne('SELECT inventory_item_id FROM mc_variant_inventory_item WHERE variant_id=? ORDER BY inventory_item_id LIMIT 1', [$exceptVariantId]);
        $params = [$sku];
        $sql = 'SELECT COUNT(*) FROM mc_inventory_item WHERE sku=?';
        if ($inventoryId !== false) {
            $sql .= ' AND id<>?';
            $params[] = (int) $inventoryId;
        }
        if ((int) $db->fetchOne($sql, $params) > 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.sku_vzhe_vykorystovuietsia_skladskoiu_pozytsiieiu'));
        }
    }

    private function assertGtinAvailable(Connection $db, ?string $gtin, ?int $exceptVariantId): void
    {
        if ($gtin === null) {
            return;
        }
        $params = [$gtin];
        $sql = 'SELECT COUNT(*) FROM mc_product_variant WHERE gtin=?';
        if ($exceptVariantId !== null) {
            $sql .= ' AND id<>?';
            $params[] = $exceptVariantId;
        }
        if ((int) $db->fetchOne($sql, $params) > 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.gtin_vzhe_vykorystovuietsia_inshym_variantom'));
        }
    }

    private function unitStep(Connection $db, string $unitCode): string
    {
        $unitCode = trim($unitCode);
        $scale = $db->fetchOne('SELECT decimal_scale FROM mc_measurement_unit WHERE code=? AND enabled=1', [$unitCode]);
        if ($scale === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.odynytsia_vymiriuvannia_ne_pidtrymuietsia'));
        }
        $scale = max(0, min(6, (int) $scale));
        return $scale === 0 ? '1.000000' : '0.' . str_repeat('0', $scale - 1) . '1' . str_repeat('0', 6 - $scale);
    }

    private function primaryLocation(Connection $db, int $marketId): int
    {
        $id = $db->fetchOne(
            'SELECT mil.location_id FROM mc_market_inventory_location mil JOIN mc_inventory_location l ON l.id=mil.location_id AND l.status=? WHERE mil.market_id=? ORDER BY mil.priority,mil.location_id LIMIT 1',
            ['active', $marketId],
        );
        if ($id === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.dlia_rynku_ne_nalashtovano_aktyvnyi_sklad'));
        }
        return (int) $id;
    }

    private function sku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '' || mb_strlen($sku, 'UTF-8') > 190 || preg_match('/[\x00-\x1F\x7F]/u', $sku) === 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.sku_oboviazkovyi_i_maie_mistyty_ne_bilshe_190_symvol'));
        }
        return $sku;
    }

    private function quantity(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        if (preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.zalyshok_maie_buty_nevidiemnym_chyslom_z_tochnistiu_'));
        }
        return number_format((float) $value, 6, '.', '');
    }

    private function nullableText(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.znachennia_perevyshchuie_dopustymu_dovzhynu'));
        }
        return $value;
    }

    private function dimensions(?float $weightKg, ?int $lengthMm, ?int $widthMm, ?int $heightMm): void
    {
        if ($weightKg !== null && ($weightKg < 0 || $weightKg > 999999999.999)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.vaha_varianta_nekorektna'));
        }
        foreach ([$lengthMm, $widthMm, $heightMm] as $dimension) {
            if ($dimension !== null && ($dimension < 0 || $dimension > 4_294_967_295)) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.productvariantservice.rozmir_varianta_nekorektnyi'));
            }
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
