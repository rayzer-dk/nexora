<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Catalog\Infrastructure\DbalCatalogAdminQuery;

/**
 * Applies the rows of the bulk editor (name, SKU, price, stock, status) and reports the values they had
 * before, so that exactly this change can be put back.
 */
final readonly class ProductBulkEditor
{
    private const STATUSES = ['draft', 'published', 'archived'];

    public function __construct(private DbalCatalogAdminQuery $query, private ProductWriter $writer)
    {
    }

    /**
     * @param array<string,mixed> $rows public id => name|sku|price|stock|status
     * @return array{ok:int,failed:list<string>,previous:array<string,array<string,string>>}
     */
    public function apply(int $storeId, int $marketId, string $locale, string $defaultCurrency, array $rows): array
    {
        $ok = 0;
        $failed = [];
        $previous = [];
        foreach ($rows as $publicId => $data) {
            if (!is_array($data)) {
                continue;
            }
            try {
                $p = $this->query->productForEdit($storeId, $marketId, $locale, (string) $publicId);
                $before = [
                    'name' => (string) $p['name'],
                    'sku' => (string) $p['sku'],
                    'price' => $p['amount_minor'] === null ? '0.00' : number_format(((int) $p['amount_minor']) / 100, 2, '.', ''),
                    'stock' => (string) ($p['stock_quantity'] ?? '0'),
                    'status' => (string) $p['status'],
                ];
                $status = in_array((string) ($data['status'] ?? ''), self::STATUSES, true) ? (string) $data['status'] : (string) $p['status'];
                $this->writer->update(new UpdateProductCommand(
                    productId: (int) $p['id'],
                    storeId: $storeId,
                    marketId: $marketId,
                    locale: $locale,
                    name: (string) ($data['name'] ?? $p['name']),
                    sku: (string) ($data['sku'] ?? $p['sku']),
                    priceMinor: $this->minor((string) ($data['price'] ?? '0')),
                    currency: (string) ($p['currency'] ?? $defaultCurrency),
                    stockQuantity: $this->qty((string) ($data['stock'] ?? $p['stock_quantity'] ?? '0')),
                    unitCode: (string) ($p['sale_unit_code'] ?? 'pcs'),
                    categoryIds: $p['category_ids'],
                    manualSlug: (string) ($p['slug'] ?? ''),
                    shortDescription: (string) ($p['short_description'] ?? ''),
                    description: (string) ($p['description'] ?? ''),
                    gtin: $p['gtin'] !== null ? (string) $p['gtin'] : null,
                    mpn: $p['mpn'] !== null ? (string) $p['mpn'] : null,
                    status: $status,
                    brandId: $p['brand_id'] !== null ? (int) $p['brand_id'] : null,
                    purchaseMode: (string) ($p['purchase_mode'] ?? 'auto'),
                    purchaseButtonLabel: $p['purchase_button_label'] !== null ? (string) $p['purchase_button_label'] : null,
                    purchaseEtaText: $p['purchase_eta_text'] !== null ? (string) $p['purchase_eta_text'] : null,
                ));
                $previous[(string) $publicId] = $before;
                ++$ok;
            } catch (\Throwable) {
                $failed[] = (string) $publicId;
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'previous' => $previous];
    }

    private function minor(string $v): int
    {
        $v = str_replace(',', '.', trim($v));
        if (preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $v) !== 1) {
            throw new \DomainException('price_invalid');
        }

        return (int) round((float) $v * 100);
    }

    private function qty(string $v): string
    {
        $v = str_replace(',', '.', trim($v));
        if (preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $v) !== 1) {
            throw new \DomainException('stock_invalid');
        }

        return number_format((float) $v, 6, '.', '');
    }
}
