<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Doctrine\DBAL\Connection;

/**
 * Per-store SEO options. "Category path in product addresses": a product is also reachable (and linked from category pages)
 * as /electronics/phones/iphone-13; the canonical address of the product stays the flat /iphone-13, so search engines see one page.
 */
final class SeoSettings
{
    /** @var array<int,bool> */
    private array $pathCache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    public function categoryPathInProductUrl(int $storeId): bool
    {
        if (!isset($this->pathCache[$storeId])) {
            try {
                $this->pathCache[$storeId] = (int) $this->db->fetchOne('SELECT product_category_path FROM mc_seo_settings WHERE store_id=?', [$storeId]) === 1;
            } catch (\Throwable) {
                $this->pathCache[$storeId] = false;
            }
        }

        return $this->pathCache[$storeId];
    }

    public function setCategoryPathInProductUrl(int $storeId, bool $enabled): void
    {
        $this->db->executeStatement(
            'INSERT INTO mc_seo_settings (store_id,product_category_path,updated_at) VALUES (?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE product_category_path=VALUES(product_category_path),updated_at=VALUES(updated_at)',
            [$storeId, $enabled ? 1 : 0],
        );
        $this->pathCache[$storeId] = $enabled;
    }
}
