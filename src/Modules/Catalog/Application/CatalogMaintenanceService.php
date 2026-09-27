<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Modules\Catalog\Application\Command\CreateProductCommand;
use Commerce\Modules\Catalog\Infrastructure\DbalCatalogAdminQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CatalogMaintenanceService
{
    public function __construct(
        private Connection $connection,
        private DbalCatalogAdminQuery $query,
        private ProductWriter $products,
        private ProductVariantService $variants,
    ) {
    }

    /** @return array{id:int,variant_id:int,public_id:string,url:string,status:string} */
    public function duplicateProductDraft(int $storeId, int $marketId, string $locale, string $publicId): array
    {
        $source = $this->query->productForEdit($storeId, $marketId, $locale, $publicId);
        $sourceProductId = (int) $source['id'];
        $sourceVariants = $this->query->variantsForEdit($sourceProductId, $storeId, $marketId);
        if ($sourceVariants === []) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.tovar_ne_maie_korektnoho_varianta_dlia_kopiiuvannia'));
        }

        $sku = $this->nextCopySku((string) $source['sku']);
        $stock = (string) ($source['stock_quantity'] ?? '0.000000');
        if ((string) $source['product_type'] === 'physical') {
            $stock = '0.000000';
        }

        $created = $this->products->create(new CreateProductCommand(
            storeId: $storeId,
            marketId: $marketId,
            locale: $locale,
            name: trim((string) $source['name']) . \Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.kopiia'),
            sku: $sku,
            priceMinor: (int) ($source['amount_minor'] ?? 0),
            currency: (string) ($source['currency'] ?? $this->marketCurrency($storeId, $marketId)),
            stockQuantity: $stock,
            unitCode: (string) ($source['sale_unit_code'] ?? 'item'),
            productType: (string) $source['product_type'],
            categoryIds: array_values(array_map('intval', $source['category_ids'] ?? [])),
            manualSlug: null,
            shortDescription: $source['short_description'] === null ? null : (string) $source['short_description'],
            description: $source['description'] === null ? null : (string) $source['description'],
            gtin: null,
            mpn: $source['mpn'] === null ? null : (string) $source['mpn'],
            brandId: $source['brand_id'] === null ? null : (int) $source['brand_id'],
        ));

        try {
            $variantMap = [(int) $source['variant_id'] => (int) $created['variant_id']];
            foreach ($sourceVariants as $variant) {
                $sourceVariantId = (int) $variant['id'];
                if ($sourceVariantId === (int) $source['variant_id']) {
                    continue;
                }
                $copy = $this->variants->create(
                    productId: (int) $created['id'],
                    storeId: $storeId,
                    marketId: $marketId,
                    sku: $this->nextCopySku((string) $variant['sku']),
                    priceMinor: (int) ($variant['amount_minor'] ?? 0),
                    stockQuantity: (string) $source['product_type'] === 'physical' ? '0.000000' : (string) ($variant['stock_quantity'] ?? '0.000000'),
                    unitCode: (string) ($variant['sale_unit_code'] ?? 'item'),
                    gtin: null,
                    mpn: $variant['mpn'] === null ? null : (string) $variant['mpn'],
                    allowBackorder: (bool) ($variant['allow_backorder'] ?? false),
                    weightKg: $variant['weight_kg'] === null ? null : (float) $variant['weight_kg'],
                    lengthMm: $variant['length_mm'] === null ? null : (int) $variant['length_mm'],
                    widthMm: $variant['width_mm'] === null ? null : (int) $variant['width_mm'],
                    heightMm: $variant['height_mm'] === null ? null : (int) $variant['height_mm'],
                );
                $variantMap[$sourceVariantId] = (int) $copy['id'];
                if ((string) ($variant['status'] ?? 'active') === 'disabled') {
                    $this->connection->update('mc_product_variant', ['status' => 'disabled'], ['id' => (int) $copy['id']]);
                }
            }

            $this->connection->transactional(function (Connection $db) use ($sourceProductId, $created, $variantMap): void {
                $newProductId = (int) $created['id'];
                $this->cloneVariantOptions($db, $sourceProductId, $newProductId, $variantMap);

                $media = $db->fetchAllAssociative(
                    'SELECT variant_id,media_asset_id,role,sort_order,alt_text FROM mc_product_media WHERE product_id=? ORDER BY sort_order,media_asset_id',
                    [$sourceProductId],
                );
                foreach ($media as $row) {
                    $sourceVariantId = $row['variant_id'] === null ? null : (int) $row['variant_id'];
                    if ($sourceVariantId !== null && !isset($variantMap[$sourceVariantId])) {
                        continue;
                    }
                    $db->insert('mc_product_media', [
                        'product_id' => $newProductId,
                        'variant_id' => $sourceVariantId === null ? null : $variantMap[$sourceVariantId],
                        'media_asset_id' => (int) $row['media_asset_id'],
                        'role' => (string) $row['role'],
                        'sort_order' => (int) $row['sort_order'],
                        'alt_text' => $row['alt_text'],
                    ]);
                }

                $attributes = $db->fetchAllAssociative(
                    'SELECT variant_id,attribute_id,locale,value_text,value_text_hash,value_decimal,value_boolean,value_json,sort_order FROM mc_product_attribute_value WHERE product_id=? ORDER BY id',
                    [$sourceProductId],
                );
                foreach ($attributes as $row) {
                    $sourceVariantId = $row['variant_id'] === null ? null : (int) $row['variant_id'];
                    if ($sourceVariantId !== null && !isset($variantMap[$sourceVariantId])) {
                        continue;
                    }
                    $db->insert('mc_product_attribute_value', [
                        'product_id' => $newProductId,
                        'variant_id' => $sourceVariantId === null ? null : $variantMap[$sourceVariantId],
                        'attribute_id' => (int) $row['attribute_id'],
                        'locale' => $row['locale'],
                        'value_text' => $row['value_text'],
                        'value_text_hash' => $row['value_text_hash'],
                        'value_decimal' => $row['value_decimal'],
                        'value_boolean' => $row['value_boolean'],
                        'value_json' => $row['value_json'],
                        'sort_order' => (int) $row['sort_order'],
                    ]);
                }

                $documents = $db->fetchAllAssociative('SELECT * FROM mc_product_document WHERE product_id=? ORDER BY id', [$sourceProductId]);
                foreach ($documents as $row) {
                    unset($row['id']);
                    $row['public_id'] = Uuid::v7()->toBinary();
                    $row['product_id'] = $newProductId;
                    $row['created_at'] = $this->now();
                    $row['updated_at'] = $this->now();
                    $db->insert('mc_product_document', $row);
                }

                $relations = $db->fetchAllAssociative(
                    'SELECT related_product_id,relation_type,sort_order FROM mc_product_relation WHERE product_id=? ORDER BY sort_order,related_product_id',
                    [$sourceProductId],
                );
                foreach ($relations as $row) {
                    if ((int) $row['related_product_id'] === $newProductId) {
                        continue;
                    }
                    $db->insert('mc_product_relation', [
                        'product_id' => $newProductId,
                        'related_product_id' => (int) $row['related_product_id'],
                        'relation_type' => (string) $row['relation_type'],
                        'sort_order' => (int) $row['sort_order'],
                        'created_at' => $this->now(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            try {
                $this->deleteProduct($storeId, (string) $created['public_id']);
            } catch (\Throwable) {
                // Keep the original failure. A cleanup failure must never hide the real cloning error.
            }
            throw $e;
        }

        return $created;
    }
    public function deleteProduct(int $storeId, string $publicId): void
    {
        $binary = Uuid::fromString($publicId)->toBinary();
        $this->connection->transactional(function (Connection $db) use ($storeId, $binary): void {
            $product = $db->fetchAssociative(
                'SELECT p.id,p.public_id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.public_id=? FOR UPDATE',
                [$storeId, $binary],
            );
            if (!is_array($product)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.tovar_ne_znaideno_v_tsomu_mahazyni'));
            }
            $productId = (int) $product['id'];
            $variantIds = array_values(array_map('intval', $db->fetchFirstColumn('SELECT id FROM mc_product_variant WHERE product_id=?', [$productId])));
            $inventoryItemIds = $variantIds === [] ? [] : array_values(array_map('intval', $db->fetchFirstColumn(
                'SELECT DISTINCT inventory_item_id FROM mc_variant_inventory_item WHERE variant_id IN (' . implode(',', array_fill(0, count($variantIds), '?')) . ')',
                $variantIds,
            )));

            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_sales_order_item WHERE product_id=?', [$productId]) > 0) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.tovar_maie_istoriiu_zamovlen_dlia_zberezhennia_audyt'));
            }
            if ($variantIds !== []) {
                $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_sales_order_item WHERE variant_id IN (' . $placeholders . ')', $variantIds) > 0) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.variant_tovaru_vykorystovuietsia_v_istorii_zamovlen_'));
                }
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_cart_item WHERE variant_id IN (' . $placeholders . ')', $variantIds) > 0) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.tovar_zaraz_znakhodytsia_u_koshyku_pokuptsia_shchob_'));
                }
            }
            if ($inventoryItemIds !== []) {
                $placeholders = implode(',', array_fill(0, count($inventoryItemIds), '?'));
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_inventory_reservation WHERE inventory_item_id IN (' . $placeholders . ')', $inventoryItemIds) > 0) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.tovar_maie_istoriiu_rezervuvannia_skladu_dlia_zberez'));
                }
            }

            $db->executeStatement("DELETE FROM mc_seo_route WHERE entity_type='product' AND entity_public_id=?", [$binary]);
            $db->executeStatement("DELETE FROM mc_entity_revision WHERE entity_type='product' AND entity_public_id=?", [$binary]);
            $db->executeStatement("DELETE FROM mc_entity_metadata WHERE entity_type='product' AND entity_public_id=?", [$binary]);
            $db->delete('mc_product', ['id' => $productId]);
            foreach ($inventoryItemIds as $inventoryItemId) {
                $db->delete('mc_inventory_item', ['id' => $inventoryItemId]);
            }
        });
    }

    public function deleteCategory(int $storeId, string $publicId): void
    {
        $binary = Uuid::fromString($publicId)->toBinary();
        $this->connection->transactional(function (Connection $db) use ($storeId, $binary): void {
            $category = $db->fetchAssociative(
                'SELECT c.id,c.public_id FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? WHERE c.public_id=? FOR UPDATE',
                [$storeId, $binary],
            );
            if (!is_array($category)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.katehoriiu_ne_znaideno_v_tsomu_mahazyni'));
            }
            $categoryId = (int) $category['id'];
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_category WHERE parent_id=?', [$categoryId]) > 0) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.katehoriia_maie_dochirni_katehorii_spochatku_peremis'));
            }
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_product_category WHERE category_id=?', [$categoryId]) > 0) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.katehoriia_vykorystovuietsia_tovaramy_spochatku_pere'));
            }

            $db->executeStatement("DELETE FROM mc_seo_route WHERE entity_type='category' AND entity_public_id=?", [$binary]);
            $db->executeStatement("DELETE FROM mc_entity_revision WHERE entity_type='category' AND entity_public_id=?", [$binary]);
            $db->executeStatement("DELETE FROM mc_entity_metadata WHERE entity_type='category' AND entity_public_id=?", [$binary]);
            $db->delete('mc_category', ['id' => $categoryId]);
        });
    }

    private function nextCopySku(string $sourceSku): string
    {
        $base = preg_replace('/[^A-Za-z0-9._\/-]+/', '-', trim($sourceSku)) ?: 'COPY';
        $base = substr($base, 0, 170);
        for ($i = 1; $i <= 999; $i++) {
            $candidate = $base . '-COPY' . ($i === 1 ? '' : '-' . $i);
            if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_product_variant WHERE sku=?', [$candidate]) === 0
                && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_inventory_item WHERE sku=?', [$candidate]) === 0) {
                return $candidate;
            }
        }
        throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.catalog.application.catalogmaintenanceservice.ne_vdalosia_sformuvaty_unikalnyi_sku_dlia_kopii_tova'));
    }

    /** @param array<int,int> $variantMap */
    private function cloneVariantOptions(Connection $db, int $sourceProductId, int $newProductId, array $variantMap): void
    {
        $optionMap = [];
        $valueMap = [];
        $options = $db->fetchAllAssociative('SELECT id,code,sort_order FROM mc_product_option WHERE product_id=? ORDER BY sort_order,id', [$sourceProductId]);
        foreach ($options as $option) {
            $oldOptionId = (int) $option['id'];
            $db->insert('mc_product_option', [
                'public_id' => Uuid::v7()->toBinary(),
                'product_id' => $newProductId,
                'code' => (string) $option['code'],
                'sort_order' => (int) $option['sort_order'],
            ]);
            $newOptionId = (int) $db->lastInsertId();
            $optionMap[$oldOptionId] = $newOptionId;
            foreach ($db->fetchAllAssociative('SELECT locale,name FROM mc_product_option_translation WHERE option_id=?', [$oldOptionId]) as $translation) {
                $db->insert('mc_product_option_translation', ['option_id'=>$newOptionId,'locale'=>(string)$translation['locale'],'name'=>(string)$translation['name']]);
            }
            foreach ($db->fetchAllAssociative('SELECT id,code,sort_order FROM mc_product_option_value WHERE option_id=? ORDER BY sort_order,id', [$oldOptionId]) as $value) {
                $oldValueId = (int) $value['id'];
                $db->insert('mc_product_option_value', [
                    'public_id' => Uuid::v7()->toBinary(),
                    'option_id' => $newOptionId,
                    'code' => (string) $value['code'],
                    'sort_order' => (int) $value['sort_order'],
                ]);
                $newValueId = (int) $db->lastInsertId();
                $valueMap[$oldValueId] = $newValueId;
                foreach ($db->fetchAllAssociative('SELECT locale,name FROM mc_product_option_value_translation WHERE option_value_id=?', [$oldValueId]) as $translation) {
                    $db->insert('mc_product_option_value_translation', ['option_value_id'=>$newValueId,'locale'=>(string)$translation['locale'],'name'=>(string)$translation['name']]);
                }
            }
        }
        if ($valueMap === [] || $variantMap === []) {
            return;
        }
        $links = $db->fetchAllAssociative('SELECT vov.variant_id,vov.option_value_id FROM mc_variant_option_value vov JOIN mc_product_variant v ON v.id=vov.variant_id WHERE v.product_id=?', [$sourceProductId]);
        foreach ($links as $link) {
            $oldVariantId = (int) $link['variant_id'];
            $oldValueId = (int) $link['option_value_id'];
            if (isset($variantMap[$oldVariantId], $valueMap[$oldValueId])) {
                $db->insert('mc_variant_option_value', ['variant_id'=>$variantMap[$oldVariantId],'option_value_id'=>$valueMap[$oldValueId]]);
            }
        }
    }

    private function marketCurrency(int $storeId, int $marketId): string
    {
        $currency = strtoupper((string) $this->connection->fetchOne('SELECT default_currency FROM mc_market WHERE id=? AND store_id=? LIMIT 1', [$marketId, $storeId]));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c560b7ef50dc'));
        }
        return $currency;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
