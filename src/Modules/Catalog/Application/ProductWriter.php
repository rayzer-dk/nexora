<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Catalog\Application\Command\CreateProductCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Seo\Application\SeoUrlManager;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ProductWriter
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private SeoUrlManager $seo,
        private HtmlSanitizerInterface $richTextSanitizer,
        private EventBusInterface $events,
        private DomainEventFactory $eventFactory,
    ) {
    }

    /** @return array{id:int,variant_id:int,public_id:string,url:string,status:string} */
    public function create(CreateProductCommand $command): array
    {
        return $this->connection->transactional(function (Connection $db) use ($command): array {
            $context = $this->resolveContext($db, $command);
            $uuid = $this->publicIds->generate();
            $now = $this->now();

            $db->insert('mc_product', [
                'public_id' => $uuid->toBinary(), 'product_type' => $command->productType, 'status' => 'draft', 'brand_id' => $command->brandId,
                'manufacturer_part_number' => $command->mpn, 'legacy_tax_class_code' => null, 'tax_class_id' => $context['tax_class_id'],
                'condition_code' => 'new', 'country_of_origin' => null, 'created_at' => $now, 'updated_at' => $now, 'row_version' => 1,
            ]);
            $productId = (int) $db->lastInsertId();

            $db->insert('mc_store_product', ['store_id' => $command->storeId, 'product_id' => $productId, 'status' => 'active', 'published_at' => null]);
            $db->insert('mc_market_product', ['market_id' => $command->marketId, 'product_id' => $productId, 'status' => 'active', 'published_at' => null]);
            $db->insert('mc_product_translation', [
                'product_id' => $productId, 'store_id' => $command->storeId, 'locale' => $command->locale, 'name' => trim($command->name), 'slug' => null,
                'short_description' => $this->plainText($command->shortDescription), 'description' => $this->richText($command->description), 'meta_title' => null, 'meta_description' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $variantUuid = $this->publicIds->generate();
            $manageInventory = $command->productType === 'physical' ? 1 : 0;
            $db->insert('mc_product_variant', [
                'public_id' => $variantUuid->toBinary(), 'product_id' => $productId, 'sku' => trim($command->sku), 'gtin' => $command->gtin,
                'mpn' => $command->mpn, 'status' => 'active', 'manage_inventory' => $manageInventory, 'allow_backorder' => in_array($command->purchaseMode, ['backorder','preorder'], true) ? 1 : 0, 'sort_order' => 0,
                'sale_unit_code' => $command->unitCode, 'quantity_step' => $context['unit_step'], 'min_order_quantity' => $context['unit_step'], 'max_order_quantity' => null,
                'unit_pricing_measure_value' => null, 'unit_pricing_measure_code' => null, 'unit_pricing_base_value' => null, 'unit_pricing_base_code' => null,
                'weight_kg' => null, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null, 'shipping_class' => null,
                'created_at' => $now, 'updated_at' => $now, 'row_version' => 1,
            ]);
            $variantId = (int) $db->lastInsertId();

            $db->insert('mc_product_purchase_policy', [
                'product_id' => $productId,
                'mode' => $command->purchaseMode,
                'button_label' => $this->nullableShortText($command->purchaseButtonLabel, 120),
                'eta_text' => $this->nullableShortText($command->purchaseEtaText, 190),
                'updated_at' => $now,
            ]);

            $db->insert('mc_price', [
                'variant_id' => $variantId, 'store_id' => $command->storeId, 'price_list_id' => null, 'market_id' => $command->marketId,
                'currency' => $command->currency, 'customer_group' => 'default', 'min_quantity' => $context['unit_step'], 'max_quantity' => null,
                'amount_minor' => $command->priceMinor, 'compare_at_minor' => null, 'tax_included' => 1, 'priority' => 100,
                'starts_at' => null, 'ends_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);

            if ($manageInventory === 1) {
                $inventoryUuid = $this->publicIds->generate();
                $db->insert('mc_inventory_item', [
                    'public_id' => $inventoryUuid->toBinary(), 'sku' => trim($command->sku), 'unit_code' => $command->unitCode,
                    'requires_shipping' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $inventoryItemId = (int) $db->lastInsertId();
                $db->insert('mc_variant_inventory_item', ['variant_id' => $variantId, 'inventory_item_id' => $inventoryItemId, 'required_quantity' => '1.000000']);
                $db->insert('mc_stock_level', [
                    'inventory_item_id' => $inventoryItemId, 'location_id' => $context['location_id'], 'stocked_quantity' => $command->stockQuantity,
                    'reserved_quantity' => '0.000000', 'incoming_quantity' => '0.000000', 'safety_stock' => '0.000000', 'row_version' => 1, 'updated_at' => $now,
                ]);
            }

            foreach (array_values(array_unique($command->categoryIds)) as $index => $categoryId) {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_category WHERE store_id = ? AND category_id = ?', [$command->storeId, $categoryId]) !== 1) {
                    throw new \DomainException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.817f655675fc'), $categoryId));
                }
                $db->insert('mc_product_category', [
                    'product_id' => $productId, 'category_id' => $categoryId, 'is_primary' => $index === 0 ? 1 : 0, 'sort_order' => $index * 10,
                ]);
            }

            $route = $this->seo->ensureForCreatedEntity(
                $command->storeId, $command->locale, SeoEntityType::Product, $uuid->toRfc4122(), $command->name, $command->manualSlug,
            );
            $this->events->publish($this->eventFactory->create(
                EventNames::PRODUCT_CREATED,
                'product',
                $uuid->toRfc4122(),
                ['store_id' => $command->storeId, 'market_id' => $command->marketId, 'status' => 'draft'],
                ['source' => 'catalog'],
            ));

            return ['id' => $productId, 'variant_id' => $variantId, 'public_id' => $uuid->toRfc4122(), 'url' => '/' . ltrim($route->path, '/'), 'status' => 'draft'];
        });
    }

    /** @return array{id:int,variant_id:int,public_id:string,url:string,status:string} */
    public function update(UpdateProductCommand $command): array
    {
        return $this->connection->transactional(function (Connection $db) use ($command): array {
            $context = $this->resolveUpdateContext($db, $command);
            $now = $this->now();
            $product = $context['product'];
            $variantId = $context['variant_id'];
            $publishedAt = $command->status === 'published' ? $now : null;
            $publicationStatus = $command->status === 'archived' ? 'inactive' : 'active';

            $db->update('mc_product', [
                'status' => $command->status,
                'manufacturer_part_number' => $command->mpn,
                'brand_id' => $command->brandId,
                'updated_at' => $now,
                'row_version' => (int) $product['row_version'] + 1,
            ], ['id' => $command->productId]);
            $db->update('mc_store_product', ['status' => $publicationStatus, 'published_at' => $publishedAt], ['store_id' => $command->storeId, 'product_id' => $command->productId]);
            $db->update('mc_market_product', ['status' => $publicationStatus, 'published_at' => $publishedAt], ['market_id' => $command->marketId, 'product_id' => $command->productId]);
            $translation = [
                'name' => trim($command->name),
                'short_description' => $this->plainText($command->shortDescription),
                'description' => $this->richText($command->description),
                'updated_at' => $now,
            ];
            if ($command->updateSeoMeta) {
                $translation['meta_title'] = $this->nullableShortText($command->metaTitle, 255);
                $translation['meta_description'] = $this->nullableShortText($command->metaDescription, 500);
            }
            $db->update('mc_product_translation', $translation, ['product_id' => $command->productId, 'store_id' => $command->storeId, 'locale' => $command->locale]);

            $db->update('mc_product_variant', [
                'sku' => trim($command->sku),
                'gtin' => $command->gtin,
                'mpn' => $command->mpn,
                'sale_unit_code' => $command->unitCode,
                'allow_backorder' => in_array($command->purchaseMode, ['backorder','preorder'], true) ? 1 : 0,
                'quantity_step' => $context['unit_step'],
                'min_order_quantity' => $context['unit_step'],
                'updated_at' => $now,
                'row_version' => $context['variant_row_version'] + 1,
            ], ['id' => $variantId]);

            $policy = [
                'mode' => $command->purchaseMode,
                'button_label' => $this->nullableShortText($command->purchaseButtonLabel, 120),
                'eta_text' => $this->nullableShortText($command->purchaseEtaText, 190),
                'updated_at' => $now,
            ];
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_product_purchase_policy WHERE product_id=?', [$command->productId]) > 0) {
                $db->update('mc_product_purchase_policy', $policy, ['product_id' => $command->productId]);
            } else {
                $db->insert('mc_product_purchase_policy', ['product_id' => $command->productId] + $policy);
            }

            $priceId = $db->fetchOne(
                "SELECT id FROM mc_price WHERE variant_id=? AND store_id=? AND market_id=? AND customer_group='default' AND price_list_id IS NULL AND max_quantity IS NULL AND starts_at IS NULL AND ends_at IS NULL ORDER BY priority ASC,min_quantity ASC,id DESC LIMIT 1",
                [$variantId, $command->storeId, $command->marketId],
            );
            $priceData = [
                'currency' => $command->currency,
                'min_quantity' => $context['unit_step'],
                'amount_minor' => $command->priceMinor,
                'tax_included' => 1,
                'updated_at' => $now,
            ];
            if ($command->updateCompareAt) {
                // The "old price" is only meaningful when it is higher than the current price.
                $priceData['compare_at_minor'] = $command->compareAtMinor !== null && $command->compareAtMinor > $command->priceMinor ? $command->compareAtMinor : null;
            }
            if ($priceId === false) {
                $db->insert('mc_price', array_merge($priceData, [
                    'variant_id' => $variantId, 'store_id' => $command->storeId, 'price_list_id' => null, 'market_id' => $command->marketId,
                    'customer_group' => 'default', 'max_quantity' => null, 'compare_at_minor' => $priceData['compare_at_minor'] ?? null, 'priority' => 100,
                    'starts_at' => null, 'ends_at' => null, 'created_at' => $now,
                ]));
            } else {
                $db->update('mc_price', $priceData, ['id' => (int) $priceId]);
            }

            if ((string) $product['product_type'] === 'physical') {
                $inventoryItemId = $db->fetchOne('SELECT inventory_item_id FROM mc_variant_inventory_item WHERE variant_id=? ORDER BY inventory_item_id LIMIT 1', [$variantId]);
                if ($inventoryItemId === false) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.c8ffddfa089a'));
                }
                $db->update('mc_inventory_item', ['sku' => trim($command->sku), 'unit_code' => $command->unitCode, 'updated_at' => $now], ['id' => (int) $inventoryItemId]);
                if ($context['stock_row_version'] > 0) {
                    $db->update('mc_stock_level', [
                        'stocked_quantity' => $command->stockQuantity,
                        'row_version' => (int) $context['stock_row_version'] + 1,
                        'updated_at' => $now,
                    ], ['inventory_item_id' => (int) $inventoryItemId, 'location_id' => $context['location_id']]);
                } else {
                    $db->insert('mc_stock_level', [
                        'inventory_item_id' => (int) $inventoryItemId, 'location_id' => $context['location_id'],
                        'stocked_quantity' => $command->stockQuantity, 'reserved_quantity' => '0.000000', 'incoming_quantity' => '0.000000',
                        'safety_stock' => '0.000000', 'row_version' => 1, 'updated_at' => $now,
                    ]);
                }
            }

            $db->delete('mc_product_category', ['product_id' => $command->productId]);
            foreach (array_values(array_unique($command->categoryIds)) as $index => $categoryId) {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_category WHERE store_id=? AND category_id=? AND status=?', [$command->storeId, $categoryId, 'active']) !== 1) {
                    throw new \DomainException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.a0c48cab73b5'), $categoryId));
                }
                $db->insert('mc_product_category', ['product_id' => $command->productId, 'category_id' => $categoryId, 'is_primary' => $index === 0 ? 1 : 0, 'sort_order' => $index * 10]);
            }

            $publicId = Uuid::fromBinary((string) $product['public_id'])->toRfc4122();
            $route = $this->seo->ensureForCreatedEntity($command->storeId, $command->locale, SeoEntityType::Product, $publicId, $command->name);
            if ($command->manualSlug !== null && trim($command->manualSlug) !== '' && trim($command->manualSlug) !== $route->slug) {
                $route = $this->seo->changeSlug($route, $command->manualSlug);
            }
            $this->events->publish($this->eventFactory->create(
                EventNames::PRODUCT_UPDATED,
                'product',
                $publicId,
                ['store_id' => $command->storeId, 'market_id' => $command->marketId, 'status' => $command->status],
                ['source' => 'catalog'],
            ));

            return ['id' => $command->productId, 'variant_id' => $variantId, 'public_id' => $publicId, 'url' => '/' . ltrim($route->path, '/'), 'status' => $command->status];
        });
    }

    /** @return array{product:array<string,mixed>,variant_id:int,variant_row_version:int,location_id:int,stock_row_version:int,unit_step:string} */
    private function resolveUpdateContext(Connection $db, UpdateProductCommand $command): array
    {
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_market WHERE id=? AND store_id=? AND status=?', [$command->marketId, $command->storeId, 'active']) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.86a11ca4bd42'));
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id=? AND locale_code=? AND enabled=1', [$command->storeId, $command->locale]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.76287ce9b814'));
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_currency WHERE store_id=? AND currency_code=? AND enabled=1', [$command->storeId, $command->currency]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.19810c784369'));
        }
        $this->assertBrand($db, $command->storeId, $command->brandId);
        $product = $db->fetchAssociative('SELECT p.* FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.id=? FOR UPDATE', [$command->storeId, $command->productId]);
        if (!is_array($product)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.d37b91c44e81'));
        }
        $variant = $db->fetchAssociative('SELECT id,row_version FROM mc_product_variant WHERE product_id=? AND sort_order=0 ORDER BY id LIMIT 1 FOR UPDATE', [$command->productId]);
        if (!is_array($variant)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7c7c725c071e'));
        }
        $decimalScale = $db->fetchOne('SELECT decimal_scale FROM mc_measurement_unit WHERE code=? AND enabled=1', [$command->unitCode]);
        if ($decimalScale === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1ccecfac2bde'));
        }
        $locationId = 0;
        $stockRowVersion = 0;
        if ((string) $product['product_type'] === 'physical') {
            $locationIdValue = $db->fetchOne('SELECT mil.location_id FROM mc_market_inventory_location mil JOIN mc_inventory_location l ON l.id=mil.location_id AND l.status=? WHERE mil.market_id=? ORDER BY mil.priority ASC,mil.location_id ASC LIMIT 1', ['active', $command->marketId]);
            if ($locationIdValue === false) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1cfeecea05f2'));
            }
            $locationId = (int) $locationIdValue;
            $inventoryItemId = $db->fetchOne('SELECT inventory_item_id FROM mc_variant_inventory_item WHERE variant_id=? ORDER BY inventory_item_id LIMIT 1', [(int) $variant['id']]);
            if ($inventoryItemId !== false) {
                $stockRowVersion = (int) ($db->fetchOne('SELECT row_version FROM mc_stock_level WHERE inventory_item_id=? AND location_id=? LIMIT 1', [(int) $inventoryItemId, $locationId]) ?: 0);
            }
        }

        return [
            'product' => $product,
            'variant_id' => (int) $variant['id'],
            'variant_row_version' => (int) $variant['row_version'],
            'location_id' => $locationId,
            'stock_row_version' => $stockRowVersion,
            'unit_step' => $this->stepForScale((int) $decimalScale),
        ];
    }

    /** @return array{tax_class_id:int,location_id:int,unit_step:string} */
    private function resolveContext(Connection $db, CreateProductCommand $command): array
    {
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_market WHERE id = ? AND store_id = ? AND status = ?', [$command->marketId, $command->storeId, 'active']) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.86a11ca4bd42'));
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_locale WHERE store_id = ? AND locale_code = ? AND enabled = 1', [$command->storeId, $command->locale]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.76287ce9b814'));
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_currency WHERE store_id = ? AND currency_code = ? AND enabled = 1', [$command->storeId, $command->currency]) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.19810c784369'));
        }
        $this->assertBrand($db, $command->storeId, $command->brandId);
        $decimalScale = $db->fetchOne('SELECT decimal_scale FROM mc_measurement_unit WHERE code = ? AND enabled = 1', [$command->unitCode]);
        if ($decimalScale === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1ccecfac2bde'));
        }
        $unitStep = $this->stepForScale((int) $decimalScale);
        $taxClassId = $db->fetchOne('SELECT id FROM mc_tax_class WHERE code = ? AND enabled = 1 LIMIT 1', ['standard']);
        if ($taxClassId === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.456a3e9ab1e7'));
        }
        $locationId = $db->fetchOne(
            'SELECT mil.location_id FROM mc_market_inventory_location mil JOIN mc_inventory_location l ON l.id = mil.location_id AND l.status = ? WHERE mil.market_id = ? ORDER BY mil.priority ASC, mil.location_id ASC LIMIT 1',
            ['active', $command->marketId],
        );
        if ($command->productType === 'physical' && $locationId === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1cfeecea05f2'));
        }
        return ['tax_class_id' => (int) $taxClassId, 'location_id' => (int) ($locationId === false ? 0 : $locationId), 'unit_step' => $unitStep];
    }



    private function assertBrand(Connection $db, int $storeId, ?int $brandId): void
    {
        if ($brandId === null) {
            return;
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM mc_store_brand WHERE store_id=? AND brand_id=? AND status=?', [$storeId, $brandId, 'active']) !== 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f6e6d482bd2c'));
        }
    }

    private function stepForScale(int $scale): string
    {
        $scale = max(0, min(6, $scale));
        if ($scale === 0) {
            return '1.000000';
        }

        return '0.' . str_repeat('0', $scale - 1) . '1' . str_repeat('0', 6 - $scale);
    }

    private function nullableShortText(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(strip_tags($value));
        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }

    private function plainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim(strip_tags($value));
        return $clean === '' ? null : $clean;
    }

    private function richText(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $clean = trim($this->richTextSanitizer->sanitize($value));
        return $clean === '' ? null : $clean;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
