<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Application;

use Commerce\Modules\Migration\Contract\MigrationSourceInterface;
use Commerce\Modules\Migration\Domain\MigrationDryRunReport;
use Commerce\Modules\Migration\Domain\MigrationEntityType;
use Commerce\Modules\Migration\Domain\MigrationIssue;

final class MigrationDryRunAnalyzer
{
    public function analyze(MigrationSourceInterface $source, int $batchSize = 250): MigrationDryRunReport
    {
        $batchSize = max(1, min(1000, $batchSize));
        $counts = [];
        $issues = [];
        $seenKeys = [];
        $categoryParents = [];
        $productCategoryRefs = [];
        $productBrandRefs = [];
        $orderProductRefs = [];
        $orderCustomerRefs = [];
        $skus = [];
        $gtins = [];
        $emails = [];

        foreach ($source->supportedEntities() as $type) {
            $cursor = null;
            do {
                $batch = $source->read($type, $cursor, $batchSize);
                foreach ($batch->records as $record) {
                    $counts[$type->value] = ($counts[$type->value] ?? 0) + 1;
                    $key = $record->sourceKey;
                    if (isset($seenKeys[$type->value][$key])) {
                        $issues[] = new MigrationIssue('error', 'duplicate_source_key', 'Duplicate source key in migration source.', $type, $key);
                    }
                    $seenKeys[$type->value][$key] = true;

                    if ($type === MigrationEntityType::Category) {
                        $categoryParents[$key] = $record->data['parent_source_key'] ?? null;
                        if (!$this->hasAnyTranslation($record->data['translations'] ?? null, 'name')) {
                            $issues[] = new MigrationIssue('error', 'category_name_missing', 'Category has no usable translated name.', $type, $key);
                        }
                    } elseif ($type === MigrationEntityType::Product) {
                        $sku = trim((string) ($record->data['sku'] ?? ''));
                        $gtin = trim((string) ($record->data['gtin'] ?? ''));
                        if ($sku === '') {
                            $issues[] = new MigrationIssue('warning', 'product_sku_missing', 'Product has no SKU; importer will generate a stable migration SKU.', $type, $key);
                        } elseif (isset($skus[mb_strtolower($sku)])) {
                            $issues[] = new MigrationIssue('error', 'duplicate_sku', 'Duplicate SKU in migration source: ' . $sku, $type, $key);
                        } else {
                            $skus[mb_strtolower($sku)] = $key;
                        }
                        if ($gtin !== '') {
                            if (isset($gtins[$gtin])) {
                                $issues[] = new MigrationIssue('error', 'duplicate_gtin', 'Duplicate GTIN in migration source: ' . $gtin, $type, $key);
                            } else {
                                $gtins[$gtin] = $key;
                            }
                        }
                        if (!$this->hasAnyTranslation($record->data['translations'] ?? null, 'name')) {
                            $issues[] = new MigrationIssue('error', 'product_name_missing', 'Product has no usable translated name.', $type, $key);
                        }
                        $price = str_replace(',', '.', trim((string) ($record->data['legacy_price_decimal'] ?? '')));
                        if ($price === '' || !is_numeric($price) || (float) $price < 0) {
                            $issues[] = new MigrationIssue('error', 'invalid_price', 'Product price is missing or invalid.', $type, $key);
                        }
                        foreach ((array) ($record->data['category_source_keys'] ?? []) as $categoryKey) {
                            $productCategoryRefs[] = [$key, (string) $categoryKey];
                        }
                        if (is_string($record->data['brand_source_key'] ?? null) && $record->data['brand_source_key'] !== '') {
                            $productBrandRefs[] = [$key, (string) $record->data['brand_source_key']];
                        }
                    } elseif ($type === MigrationEntityType::Customer) {
                        $email = mb_strtolower(trim((string) ($record->data['email'] ?? '')));
                        if ($email !== '') {
                            if (isset($emails[$email])) {
                                $issues[] = new MigrationIssue('warning', 'duplicate_customer_email', 'Multiple source customers use the same email; they may map to one target customer.', $type, $key);
                            } else {
                                $emails[$email] = $key;
                            }
                        }
                    } elseif ($type === MigrationEntityType::Order) {
                        if (is_string($record->data['customer_source_key'] ?? null) && $record->data['customer_source_key'] !== '') {
                            $orderCustomerRefs[] = [$key, (string) $record->data['customer_source_key']];
                        }
                        foreach ((array) ($record->data['items'] ?? []) as $item) {
                            if (is_array($item) && (string) ($item['product_source_key'] ?? '') !== '') {
                                $orderProductRefs[] = [$key, (string) $item['product_source_key']];
                            }
                        }
                    }
                }
                $cursor = $batch->nextCursor;
            } while (!$batch->complete);
        }

        foreach ($categoryParents as $key => $parent) {
            if ($parent !== null && $parent !== '' && !isset($seenKeys[MigrationEntityType::Category->value][(string) $parent])) {
                $issues[] = new MigrationIssue('error', 'broken_category_parent', 'Category references a missing parent source key.', MigrationEntityType::Category, (string) $key);
            }
            if ((string) $parent === (string) $key) {
                $issues[] = new MigrationIssue('error', 'category_self_parent', 'Category references itself as parent.', MigrationEntityType::Category, (string) $key);
            }
        }
        $this->detectCategoryCycles($categoryParents, $issues);

        foreach ($productCategoryRefs as [$productKey, $categoryKey]) {
            if (!isset($seenKeys[MigrationEntityType::Category->value][$categoryKey])) {
                $issues[] = new MigrationIssue('warning', 'product_category_missing', 'Product references a category that is not present in the source.', MigrationEntityType::Product, $productKey);
            }
        }
        foreach ($productBrandRefs as [$productKey, $brandKey]) {
            if (!isset($seenKeys[MigrationEntityType::Brand->value][$brandKey])) {
                $issues[] = new MigrationIssue('warning', 'product_brand_missing', 'Product references a brand that is not present in the source.', MigrationEntityType::Product, $productKey);
            }
        }
        foreach ($orderCustomerRefs as [$orderKey, $customerKey]) {
            if (!isset($seenKeys[MigrationEntityType::Customer->value][$customerKey])) {
                $issues[] = new MigrationIssue('warning', 'order_customer_missing', 'Order references a customer that is not present; order snapshot can still be imported.', MigrationEntityType::Order, $orderKey);
            }
        }
        foreach ($orderProductRefs as [$orderKey, $productKey]) {
            if (!isset($seenKeys[MigrationEntityType::Product->value][$productKey])) {
                $issues[] = new MigrationIssue('warning', 'order_product_missing', 'Order item references a product that is not present; immutable order snapshot can still be imported.', MigrationEntityType::Order, $orderKey);
            }
        }

        return new MigrationDryRunReport($counts, $issues);
    }

    private function hasAnyTranslation(mixed $translations, string $field): bool
    {
        if (!is_array($translations)) {
            return false;
        }
        foreach ($translations as $translation) {
            if (is_array($translation) && trim((string) ($translation[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $parents @param list<MigrationIssue> $issues */
    private function detectCategoryCycles(array $parents, array &$issues): void
    {
        foreach (array_keys($parents) as $start) {
            $seen = [];
            $current = (string) $start;
            for ($depth = 0; $depth < 256; $depth++) {
                if (isset($seen[$current])) {
                    $issues[] = new MigrationIssue('error', 'category_cycle', 'Category hierarchy contains a cycle.', MigrationEntityType::Category, (string) $start);
                    break;
                }
                $seen[$current] = true;
                $parent = $parents[$current] ?? null;
                if ($parent === null || $parent === '') {
                    break;
                }
                $current = (string) $parent;
            }
        }
    }
}
