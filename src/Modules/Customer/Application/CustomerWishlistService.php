<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Storefront\Infrastructure\StorefrontMoneyFormatter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CustomerWishlistService
{
    public function __construct(
        private Connection $db,
        private StorefrontMoneyFormatter $money,
    ) {
    }

    public function add(int $customerId, StorefrontContext $context, string $productPublicId): void
    {
        $productId = $this->activeProductId($context, $productPublicId);
        if ($productId === null) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerwishlistservice.tovar_ne_znaideno_abo_vin_nedostupnyi'));
        }
        $this->db->executeStatement(
            'INSERT IGNORE INTO mc_customer_wishlist (customer_id,store_id,product_id,created_at) VALUES (?,?,?,?)',
            [$customerId, $context->storeId, $productId, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')],
        );
    }

    public function remove(int $customerId, int $storeId, string $productPublicId): void
    {
        try {
            $binary = Uuid::fromString($productPublicId)->toBinary();
        } catch (\Throwable) {
            return;
        }
        $this->db->executeStatement(
            'DELETE cw FROM mc_customer_wishlist cw JOIN mc_product p ON p.id=cw.product_id WHERE cw.customer_id=? AND cw.store_id=? AND p.public_id=?',
            [$customerId, $storeId, $binary],
        );
    }

    /** @return list<array<string,mixed>> */
    public function products(int $customerId, StorefrontContext $context, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.public_id,pt.name,v.sku,pr.amount_minor,pr.currency,sr.path,
                    COALESCE(b.name,'') brand_name,
                    (SELECT ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=p.id AND pm.role IN ('primary','gallery') ORDER BY (pm.role='primary') DESC,pm.sort_order ASC LIMIT 1) image_key
             FROM mc_customer_wishlist cw
             JOIN mc_product p ON p.id=cw.product_id AND p.status='published'
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=cw.store_id AND sp.status='active'
             JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active'
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=cw.store_id AND pt.locale=?
             JOIN mc_product_variant v ON v.product_id=p.id AND v.status='active' AND v.sort_order=0
             LEFT JOIN mc_brand b ON b.id=p.brand_id
             LEFT JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=cw.store_id AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
             JOIN mc_seo_route sr ON sr.store_id=cw.store_id AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
             WHERE cw.customer_id=? AND cw.store_id=?
             ORDER BY cw.created_at DESC LIMIT {$limit}",
            [$context->marketId, $context->locale, $context->marketId, $context->currency, $context->locale, $customerId, $context->storeId],
        );

        return array_map(function (array $row) use ($context): array {
            $storageKey = is_string($row['image_key'] ?? null) ? str_replace('\\', '/', trim((string) $row['image_key'])) : '';
            $image = $storageKey !== '' && !str_contains($storageKey, '..') ? '/media/'.ltrim($storageKey, '/') : '/assets/product-placeholder.svg';
            $priceMinor = (int) ($row['amount_minor'] ?? 0);
            $currency = (string) ($row['currency'] ?? $context->currency);
            return [
                'id' => Uuid::fromBinary((string) $row['public_id'])->toRfc4122(),
                'name' => (string) $row['name'],
                'brand' => (string) $row['brand_name'],
                'sku' => (string) $row['sku'],
                'price' => $this->money->format($priceMinor, $currency, $context->locale),
                'url' => '/'.ltrim((string) $row['path'], '/'),
                'image' => $image,
            ];
        }, $rows);
    }

    public function contains(int $customerId, int $storeId, string $productPublicId): bool
    {
        try {
            $binary = Uuid::fromString($productPublicId)->toBinary();
        } catch (\Throwable) {
            return false;
        }
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM mc_customer_wishlist cw JOIN mc_product p ON p.id=cw.product_id WHERE cw.customer_id=? AND cw.store_id=? AND p.public_id=?',
            [$customerId, $storeId, $binary],
        ) > 0;
    }

    private function activeProductId(StorefrontContext $context, string $publicId): ?int
    {
        try {
            $binary = Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            return null;
        }
        $id = $this->db->fetchOne(
            "SELECT p.id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active' JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active' WHERE p.public_id=? AND p.status='published' LIMIT 1",
            [$context->storeId, $context->marketId, $binary],
        );
        return $id !== false ? (int) $id : null;
    }
}
