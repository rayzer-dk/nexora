<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Commerce\Modules\Search\Application\SearchSynonymService;
use Commerce\Modules\Search\Contract\SearchCandidateProviderInterface;
use Commerce\Modules\Storefront\Domain\ProductCatalogFilter;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalStorefrontCatalogQuery
{
    public function __construct(
        private Connection $connection,
        private StorefrontMoneyFormatter $money,
        private SearchSynonymService $synonyms,
        private SearchCandidateProviderInterface $searchCandidates,
        private \Commerce\Modules\Catalog\Application\ProductBadgeService $badges,
        private \Commerce\Modules\Media\Application\MediaVariantService $variants,
        private \Commerce\Modules\Media\Application\ProductVideoService $videos,
        private CategoryTreeIndex $tree,
        private ?\Commerce\Modules\Search\Application\SqlSearchIndex $searchIndex = null,
        private ?\Commerce\Modules\Catalog\Application\ProductAddonService $addons = null,
        private ?\Commerce\Modules\Catalog\Application\ImageAltService $imageAlt = null,
    ) {
    }

    /**
     * Top-level categories for the home page. $order: manual (the Order field of each category), name, popular (most
     * products first) or newest. Each tile has a photo (its own, else that of a product somewhere in its branch) and
     * the number of products in the whole branch.
     *
     * @return list<array<string,mixed>>
     */
    public function topCategories(StorefrontContext $context, int $limit = 24, string $order = 'manual'): array
    {
        return $this->categoryTiles($context, null, $limit, $order);
    }

    /**
     * Direct subcategories of a category, as photo tiles.
     *
     * @return list<array<string,mixed>>
     */
    public function childCategories(StorefrontContext $context, int $parentId, int $limit = 60, string $order = 'manual'): array
    {
        return $this->categoryTiles($context, $parentId, $limit, $order);
    }

    /** @return list<array{name:string,url:string}> the parents of a category from the top level down, for breadcrumbs */
    public function categoryTrail(StorefrontContext $context, int $categoryId): array
    {
        $ids = $this->tree->ancestors($categoryId, $context->storeId);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.id,ct.name,sr.path FROM mc_category c
             JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=?
             JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='category' AND sr.entity_public_id=c.public_id
             WHERE c.id IN ({$in})",
            [$context->storeId, $context->locale, $context->storeId, $context->locale],
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = ['name' => (string) $row['name'], 'url' => '/' . ltrim((string) $row['path'], '/')];
        }
        $trail = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $trail[] = $byId[$id];
            }
        }

        return $trail;
    }

    /** Comma-separated integer ids of a category branch, safe to put into SQL. */
    private function scopeSql(int $categoryId, int $storeId): string
    {
        return implode(',', array_map('intval', $this->tree->scope($categoryId, $storeId)));
    }

    /** @return list<array<string,mixed>> */
    private function categoryTiles(StorefrontContext $context, ?int $parentId, int $limit, string $order): array
    {
        $limit = max(1, min(200, $limit));
        $parentSql = $parentId === null ? 'c.parent_id IS NULL' : 'c.parent_id=' . (int) $parentId;
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.id,c.public_id,c.icon,COALESCE(ct.name,dt.name) AS name,COALESCE(ct.description,dt.description) AS description,COALESCE(sr.path,dr.path) AS path,
                    (SELECT cma.storage_key FROM mc_category_image cix JOIN mc_media_asset cma ON cma.id=cix.asset_id WHERE cix.category_id=c.id LIMIT 1) AS image_key
             FROM mc_category c
             JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active'
             JOIN mc_market_category mk ON mk.category_id=c.id AND mk.market_id=? AND mk.status='active'
             LEFT JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=?
             LEFT JOIN mc_category_translation dt ON dt.category_id=c.id AND dt.store_id=? AND dt.locale=(SELECT default_locale FROM mc_store WHERE id=?)
             LEFT JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='category' AND sr.entity_public_id=c.public_id
             LEFT JOIN mc_seo_route dr ON dr.store_id=? AND dr.locale=(SELECT default_locale FROM mc_store WHERE id=?) AND dr.entity_type='category' AND dr.entity_public_id=c.public_id
             WHERE c.status='active' AND {$parentSql} AND COALESCE(ct.name,dt.name) IS NOT NULL AND COALESCE(sr.path,dr.path) IS NOT NULL
             ORDER BY sc.sort_order ASC,c.sort_order ASC,c.id ASC LIMIT 200",
            [$context->storeId, $context->marketId, $context->storeId, $context->locale, $context->storeId, $context->storeId, $context->storeId, $context->locale, $context->storeId, $context->storeId],
        );
        $tiles = [];
        foreach ($rows as $row) {
            $ids = $this->scopeSql((int) $row['id'], $context->storeId);
            if (($row['image_key'] ?? null) === null || $row['image_key'] === '') {
                $row['image_key'] = $this->connection->fetchOne(
                    "SELECT ma.storage_key FROM mc_product_category pcx
                     JOIN mc_product px ON px.id=pcx.product_id AND px.status='published'
                     JOIN mc_product_media pm ON pm.product_id=px.id AND pm.role IN ('primary','gallery')
                     JOIN mc_media_asset ma ON ma.id=pm.media_asset_id
                     WHERE pcx.category_id IN ({$ids})
                     ORDER BY (pm.role='primary') DESC,pm.sort_order ASC,px.id ASC LIMIT 1",
                ) ?: null;
            }
            $tile = $this->categoryRow($row);
            $tile['icon'] = (string) ($row['icon'] ?? '');
            $tile['product_count'] = (int) $this->connection->fetchOne(
                "SELECT COUNT(DISTINCT p.id) FROM mc_product p
                 JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active'
                 JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active'
                 WHERE p.status='published' AND EXISTS (SELECT 1 FROM mc_product_category pc WHERE pc.product_id=p.id AND pc.category_id IN ({$ids}))",
                [$context->storeId, $context->marketId],
            );
            $tiles[] = $tile;
        }
        if ($order === 'name') {
            usort($tiles, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        } elseif ($order === 'popular') {
            usort($tiles, static fn (array $a, array $b): int => $b['product_count'] <=> $a['product_count']);
        } elseif ($order === 'newest') {
            usort($tiles, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        }

        return array_slice($tiles, 0, $limit);
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int} */
    public function products(
        StorefrontContext $context,
        ?int $categoryId = null,
        int $page = 1,
        int $limit = 24,
        ?string $search = null,
        ?ProductCatalogFilter $filter = null,
    ): array {
        $page = max(1, $page);
        $limit = min(60, max(1, $limit));
        $offset = ($page - 1) * $limit;
        $filter ??= new ProductCatalogFilter(search: trim((string) $search));

        $conditions = [
            "p.status='published'",
            "sp.status='active'",
            "mp.status='active'",
            '(sp.published_at IS NULL OR sp.published_at<=UTC_TIMESTAMP(6))',
            '(mp.published_at IS NULL OR mp.published_at<=UTC_TIMESTAMP(6))',
            "NOT EXISTS (SELECT 1 FROM mc_product_extra pxh WHERE pxh.product_id=p.id AND pxh.hidden=1)",
        ];
        $filterParams = [];

        if ($categoryId !== null) {
            // A category lists the products of its whole branch, so a parent such as "Electronics" is never empty.
            $conditions[] = 'EXISTS (SELECT 1 FROM mc_product_category fpc WHERE fpc.product_id=p.id AND fpc.category_id IN (' . $this->scopeSql($categoryId, $context->storeId) . '))';
        }
        if ($filter->brandIds !== []) {
            $conditions[] = 'p.brand_id IN (' . implode(',', array_fill(0, count($filter->brandIds), '?')) . ')';
            array_push($filterParams, ...$filter->brandIds);
        }

        $stockExpression = "COALESCE((SELECT SUM(GREATEST(fsl.stocked_quantity-fsl.reserved_quantity-fsl.safety_stock,0)) FROM mc_variant_inventory_item fvii JOIN mc_stock_level fsl ON fsl.inventory_item_id=fvii.inventory_item_id JOIN mc_market_inventory_location fmil ON fmil.location_id=fsl.location_id AND fmil.market_id=? WHERE fvii.variant_id=v.id),0)";
        if ($filter->inStockOnly) {
            $conditions[] = '(' . $stockExpression . " > 0 OR EXISTS (SELECT 1 FROM mc_product_variant uv WHERE uv.product_id=p.id AND uv.manage_inventory=0 AND p.product_type='physical'))";
            $filterParams[] = $context->marketId;
        }
        if ($filter->minPriceMinor !== null) {
            $conditions[] = 'pr.amount_minor>=?';
            $filterParams[] = $filter->minPriceMinor;
        }
        if ($filter->maxPriceMinor !== null) {
            $conditions[] = 'pr.amount_minor<=?';
            $filterParams[] = $filter->maxPriceMinor;
        }
        if ($filter->minRating > 0) {
            $conditions[] = "COALESCE((SELECT AVG(frv.rating) FROM mc_product_review frv WHERE frv.product_id=p.id AND frv.status='published'),0)>=?";
            $filterParams[] = $filter->minRating;
        }

        foreach ($filter->attributeFilters as $attributeCode => $selectedValues) {
            $valueSql = [];
            $valueParams = [];
            foreach ($selectedValues as $selectedValue) {
                if (str_starts_with($selectedValue, 'b:') && in_array(substr($selectedValue, 2), ['0', '1'], true)) {
                    $valueSql[] = 'fav.value_boolean=?';
                    $valueParams[] = (int) substr($selectedValue, 2);
                    continue;
                }
                if (str_starts_with($selectedValue, 'd:')) {
                    $decimal = substr($selectedValue, 2);
                    if (preg_match('/^-?\d{1,19}(?:\.\d{1,10})?$/D', $decimal) === 1) {
                        $valueSql[] = 'fav.value_decimal=?';
                        $valueParams[] = $decimal;
                    }
                    continue;
                }
                if (str_starts_with($selectedValue, 't:')) {
                    $hash = substr($selectedValue, 2);
                    if (preg_match('/^[A-Fa-f0-9]{64}$/D', $hash) === 1) {
                        $valueSql[] = 'fav.value_text_hash=UNHEX(?)';
                        $valueParams[] = strtoupper($hash);
                    }
                }
            }
            if ($valueSql !== []) {
                $conditions[] = "EXISTS (SELECT 1 FROM mc_product_attribute_value fav JOIN mc_attribute_definition fad ON fad.id=fav.attribute_id WHERE fav.product_id=p.id AND fav.variant_id IS NULL AND fad.code=? AND fad.filterable=1 AND (fav.locale IS NULL OR fav.locale=?) AND (" . implode(' OR ', $valueSql) . '))';
                $filterParams[] = $attributeCode;
                $filterParams[] = $context->locale;
                array_push($filterParams, ...$valueParams);
            }
        }

        $acceleratedSearchIds = null;
        if (trim($filter->search) !== '') {
            $accelerated = $this->searchCandidates->candidates($context, $filter->search, 5000);
            if ($accelerated !== null) {
                $acceleratedSearchIds = $accelerated->productIds;
                if ($acceleratedSearchIds === []) {
                    return ['items'=>[], 'total'=>0, 'page'=>$page, 'pages'=>1];
                }
                $conditions[] = 'p.id IN (' . implode(',', array_fill(0, count($acceleratedSearchIds), '?')) . ')';
                array_push($filterParams, ...$acceleratedSearchIds);
            } else {
                $tokens = $this->searchTokens($filter->search);
                $tokenGroups = $this->synonyms->expandTokenGroups($context->storeId, $context->locale, $tokens);
                if ($this->searchIndex !== null && $this->searchIndex->isAvailable($context->storeId, $context->locale)) {
                    // Built-in index: names in every language, brand, categories, SKU; word forms, typos, the other alphabet and keyboard layout.
                    $prepared = $this->searchIndex->prepare($context->storeId, $context->locale, array_map(static fn (array $g): array => array_slice($g, 0, 6), $tokenGroups));
                    foreach ($prepared['groups'] as $stems) {
                        $likes = [];
                        foreach ($stems as $stem) {
                            $likes[] = "sd.document LIKE ? ESCAPE '!'";
                            $filterParams[] = '%' . $this->escapeLike($stem) . '%';
                        }
                        $conditions[] = '(EXISTS (SELECT 1 FROM mc_search_document sd WHERE sd.store_id=? AND sd.locale=? AND sd.product_id=p.id AND (' . implode(' OR ', $likes) . ')))';
                        array_splice($filterParams, count($filterParams) - count($stems), 0, [$context->storeId, $context->locale]);
                    }
                    $tokenGroups = [];
                }
                foreach ($tokenGroups as $alternatives) {
                    $alternativeSql = [];
                    foreach (array_slice($alternatives, 0, 6) as $token) {
                        $like = '%' . $this->escapeLike($token) . '%';
                        $alternativeSql[] = "(pt.name LIKE ? ESCAPE '!' OR COALESCE(pt.short_description,'') LIKE ? ESCAPE '!' OR v.sku LIKE ? ESCAPE '!' OR COALESCE(v.gtin,'') LIKE ? ESCAPE '!' OR COALESCE(v.mpn,'') LIKE ? ESCAPE '!' OR COALESCE(b.name,'') LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM mc_product_attribute_value fpav WHERE fpav.product_id=p.id AND COALESCE(fpav.value_text,'') LIKE ? ESCAPE '!'))";
                        array_push($filterParams, $like, $like, $like, $like, $like, $like, $like);
                    }
                    if ($alternativeSql !== []) {
                        $conditions[] = '(' . implode(' OR ', $alternativeSql) . ')';
                    }
                }
            }
        }

        $where = implode(' AND ', $conditions);
        $priceJoin = "LEFT JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)";

        $baseJoins = "FROM mc_product p
                JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
                JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=?
                JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=?
                JOIN mc_product_variant v ON v.product_id=p.id AND v.status='active' AND v.sort_order=0
                LEFT JOIN mc_brand b ON b.id=p.brand_id
                {$priceJoin}";

        $baseParams = [
            $context->storeId,
            $context->marketId,
            $context->storeId,
            $context->locale,
            $context->storeId,
            $context->marketId,
            $context->currency,
        ];
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT p.id) {$baseJoins} WHERE {$where}",
            [...$baseParams, ...$filterParams],
        );

        $orderParams = [];
        $safeStoreId = (int) $context->storeId;
        $popularJoin = $filter->sort === ProductCatalogFilter::SORT_POPULAR
            ? "LEFT JOIN (SELECT soi.product_id,SUM(soi.quantity) AS sold FROM mc_sales_order_item soi JOIN mc_sales_order so ON so.id=soi.order_id AND so.store_id={$safeStoreId} AND so.status NOT IN ('cancelled','expired','rejected') AND so.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY) GROUP BY soi.product_id) pop ON pop.product_id=p.id"
            : '';
        $orderBy = match ($filter->sort) {
            ProductCatalogFilter::SORT_PRICE_ASC => 'pr.amount_minor IS NULL ASC,pr.amount_minor ASC,p.id DESC',
            ProductCatalogFilter::SORT_PRICE_DESC => 'pr.amount_minor IS NULL ASC,pr.amount_minor DESC,p.id DESC',
            ProductCatalogFilter::SORT_NAME_ASC => 'pt.name ASC,p.id DESC',
            ProductCatalogFilter::SORT_NAME_DESC => 'pt.name DESC,p.id DESC',
            // Sales of the last 180 days come from one grouped join (see $popularJoin): a correlated subquery per product made the home page
            // and every "popular" list grow linearly with the catalog (0.6 s at 10 000 products).
            ProductCatalogFilter::SORT_POPULAR => 'COALESCE(pop.sold,0) DESC,sp.published_at DESC,p.id DESC',
            default => 'sp.published_at DESC,p.updated_at DESC,p.id DESC',
        };
        if ($categoryId !== null && $filter->sort === ProductCatalogFilter::SORT_NEWEST && trim($filter->search) === '') {
            try {
                $merchMode = (string) ($this->connection->fetchOne('SELECT mode FROM mc_category_merchandising WHERE category_id=? AND store_id=?', [$categoryId, $context->storeId]) ?: 'manual');
                $safeCategoryId = max(1, (int) $categoryId);
                $manualOrder = "COALESCE((SELECT mpc.sort_order FROM mc_product_category mpc WHERE mpc.product_id=p.id AND mpc.category_id={$safeCategoryId} LIMIT 1),999999) ASC,p.id DESC";
                $orderBy = match ($merchMode) {
                    'price_desc' => 'pr.amount_minor IS NULL ASC,pr.amount_minor DESC,p.id DESC',
                    'rating' => 'rating_value DESC,review_count DESC,p.id DESC',
                    'stock' => 'available_quantity DESC,p.id DESC',
                    'newest' => 'sp.published_at DESC,p.updated_at DESC,p.id DESC',
                    default => $manualOrder,
                };
            } catch (\Throwable) {
                // Merchandising is optional. Catalog falls back to normal ordering if its table is unavailable.
            }
        }
        if ($filter->search !== '' && $filter->sort === ProductCatalogFilter::SORT_NEWEST) {
            if (is_array($acceleratedSearchIds) && $acceleratedSearchIds !== []) {
                $orderBy = 'FIELD(p.id,' . implode(',', array_fill(0, count($acceleratedSearchIds), '?')) . '),p.id DESC';
                $orderParams = $acceleratedSearchIds;
            } else {
                $exact = mb_substr(trim($filter->search), 0, 190, 'UTF-8');
                $prefix = $this->escapeLike($exact) . '%';
                $orderBy = "CASE WHEN v.sku=? OR v.gtin=? OR v.mpn=? THEN 0 WHEN pt.name=? THEN 1 WHEN pt.name LIKE ? ESCAPE '!' THEN 2 ELSE 3 END,sp.published_at DESC,p.updated_at DESC,p.id DESC";
                $orderParams = [$exact, $exact, $exact, $exact, $prefix];
            }
        }
        if ($filter->search !== '' && $filter->sort === ProductCatalogFilter::SORT_NEWEST) {
            try {
                $normalizedBoostQuery = mb_strtolower(trim(preg_replace('/\s+/u',' ', $filter->search) ?? $filter->search), 'UTF-8');
                $boostIds = array_map('intval', $this->connection->fetchFirstColumn(
                    "SELECT product_id FROM mc_search_boost WHERE store_id=? AND locale=? AND status='active' AND query_hash=? ORDER BY weight DESC,id ASC LIMIT 20",
                    [$context->storeId,$context->locale,hash('sha256',$normalizedBoostQuery,true)],
                ));
                if ($boostIds !== []) {
                    $ph = implode(',', array_fill(0,count($boostIds),'?'));
                    $orderBy = 'CASE WHEN p.id IN (' . $ph . ') THEN 0 ELSE 1 END, FIELD(p.id,' . $ph . ') ASC,' . $orderBy;
                    $orderParams = [...$boostIds, ...$boostIds, ...$orderParams];
                }
            } catch (\Throwable) {
                // Search boosts are optional; organic ordering remains available.
            }
        }

        $selectList = "p.id,p.public_id,p.product_type,pt.name,pt.short_description,v.id AS variant_id,v.public_id AS variant_public_id,v.sku,v.sale_unit_code,v.quantity_step,v.min_order_quantity,v.max_order_quantity,
                       pr.amount_minor,pr.compare_at_minor,pr.ends_at AS price_ends_at,pr.currency,pr.tax_included,sr.path,
                       COALESCE(b.name,'') AS brand_name,
                       COALESCE((SELECT AVG(rv.rating) FROM mc_product_review rv WHERE rv.product_id=p.id AND rv.status='published'),0) AS rating_value,
                       (SELECT COUNT(*) FROM mc_product_review rc WHERE rc.product_id=p.id AND rc.status='published') AS review_count,
                       tr.rate_bps,
                       COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id),0) AS available_quantity,
                       (SELECT ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=p.id AND pm.role IN ('primary','gallery') ORDER BY (pm.role='primary') DESC,pm.sort_order ASC LIMIT 1) AS image_key";
        $fromWhere = static fn (string $w): string => "                {$baseJoins}
                {$popularJoin}
                JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
                LEFT JOIN mc_tax_rate tr ON tr.id=(SELECT tx.id FROM mc_tax_rate tx WHERE tx.tax_class_id=p.tax_class_id AND tx.country_code=? AND tx.enabled=1 AND tx.valid_from<=UTC_TIMESTAMP(6) AND (tx.valid_to IS NULL OR tx.valid_to>UTC_TIMESTAMP(6)) ORDER BY tx.priority ASC,tx.id DESC LIMIT 1)
                WHERE {$w}";
        $joinParams = [...$baseParams, $context->storeId, $context->locale, $context->countryCode];
        // Two steps: the ids of the page first (cheap columns only), then the card data for those few products. Computing
        // the rating, review count, stock and photo for every candidate before sorting made every list page cost
        // ~0.6 s at 10 000 products and grow with the catalog. Merchandising orders that sort by those computed columns keep the single query.
        $offset0 = $offset;
        if (preg_match('/\b(rating_value|review_count|available_quantity)\b/', $orderBy) !== 1) {
            $pageIds = array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT p.id ' . $fromWhere($where) . " ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}",
                [...$joinParams, ...$filterParams, ...$orderParams],
            ));
            if ($pageIds === []) {
                return ['items' => [], 'total' => $count, 'page' => $page, 'pages' => max(1, (int) ceil($count / $limit))];
            }
            $where .= ' AND p.id IN (' . implode(',', $pageIds) . ')';
            $offset0 = 0;
        }
        $sql = "SELECT {$selectList} " . $fromWhere($where) . " ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset0}";
        $queryParams = [
            $context->marketId,
            ...$joinParams,
            ...$filterParams,
            ...$orderParams,
        ];
        $rows = $this->connection->fetchAllAssociative($sql, $queryParams);
        $items = $this->badges->decorate(array_map(fn(array $r): array => $this->productCardRow($r, $context), $rows), $context->storeId, $context->locale);
        return ['items'=>$items,'total'=>$count,'page'=>$page,'pages'=>max(1,(int)ceil($count/$limit))];
    }


    /** @return array{items:list<array<string,mixed>>,next_cursor:?int} */
    public function productsByCursor(StorefrontContext $context, ?int $categoryId = null, ?int $afterProductId = null, int $limit = 24): array
    {
        $limit = min(60, max(1, $limit));
        $conditions = [
            "p.status='published'",
            "sp.status='active'",
            "mp.status='active'",
            '(sp.published_at IS NULL OR sp.published_at<=UTC_TIMESTAMP(6))',
            '(mp.published_at IS NULL OR mp.published_at<=UTC_TIMESTAMP(6))',
            "NOT EXISTS (SELECT 1 FROM mc_product_extra pxh WHERE pxh.product_id=p.id AND pxh.hidden=1)",
        ];
        $filterParams = [];
        if ($categoryId !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM mc_product_category cpc WHERE cpc.product_id=p.id AND cpc.category_id IN (' . $this->scopeSql($categoryId, $context->storeId) . '))';
        }
        if ($afterProductId !== null && $afterProductId > 0) {
            $conditions[] = 'p.id<?';
            $filterParams[] = $afterProductId;
        }
        $where = implode(' AND ', $conditions);
        $fetchLimit = $limit + 1;
        $sql = "SELECT p.id,p.public_id,p.product_type,pt.name,pt.short_description,v.id AS variant_id,v.public_id AS variant_public_id,v.sku,v.sale_unit_code,v.quantity_step,v.min_order_quantity,v.max_order_quantity,
                       pr.amount_minor,pr.compare_at_minor,pr.ends_at AS price_ends_at,pr.currency,pr.tax_included,sr.path,
                       COALESCE(b.name,'') AS brand_name,
                       COALESCE((SELECT AVG(rv.rating) FROM mc_product_review rv WHERE rv.product_id=p.id AND rv.status='published'),0) AS rating_value,
                       (SELECT COUNT(*) FROM mc_product_review rc WHERE rc.product_id=p.id AND rc.status='published') AS review_count,
                       tr.rate_bps,
                       COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id),0) AS available_quantity,
                       (SELECT ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=p.id AND pm.role IN ('primary','gallery') ORDER BY (pm.role='primary') DESC,pm.sort_order ASC LIMIT 1) AS image_key
                FROM mc_product p
                JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=?
                JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=?
                JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=?
                JOIN mc_product_variant v ON v.product_id=p.id AND v.status='active' AND v.sort_order=0
                LEFT JOIN mc_brand b ON b.id=p.brand_id
                LEFT JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
                JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
                LEFT JOIN mc_tax_rate tr ON tr.id=(SELECT tx.id FROM mc_tax_rate tx WHERE tx.tax_class_id=p.tax_class_id AND tx.country_code=? AND tx.enabled=1 AND tx.valid_from<=UTC_TIMESTAMP(6) AND (tx.valid_to IS NULL OR tx.valid_to>UTC_TIMESTAMP(6)) ORDER BY tx.priority ASC,tx.id DESC LIMIT 1)
                WHERE {$where}
                ORDER BY p.id DESC
                LIMIT {$fetchLimit}";
        $params = [
            $context->marketId,
            $context->storeId,$context->marketId,$context->storeId,$context->locale,
            $context->storeId,$context->marketId,$context->currency,
            $context->storeId,$context->locale,$context->countryCode,
            ...$filterParams,
        ];
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = $this->badges->decorate(array_map(fn(array $r): array => $this->productCardRow($r, $context), $rows), $context->storeId, $context->locale);
        $next = $hasMore && $rows !== [] ? (int)$rows[array_key_last($rows)]['id'] : null;
        return ['items'=>$items,'next_cursor'=>$next];
    }

    /** @return array{brands:list<array{id:int,name:string,count:int}>,price_min_minor:?int,price_max_minor:?int,attributes:list<array{code:string,name:string,data_type:string,unit_label:?string,values:list<array{token:string,label:string,count:int}>}>} */
    public function catalogFacets(StorefrontContext $context, ?int $categoryId = null): array
    {
        $categorySql = $categoryId === null ? '' : ' AND EXISTS (SELECT 1 FROM mc_product_category fpc WHERE fpc.product_id=p.id AND fpc.category_id IN (' . $this->scopeSql($categoryId, $context->storeId) . '))';
        $params = [$context->storeId, $context->marketId];
        $brands = $this->connection->fetchAllAssociative(
            "SELECT b.id,b.name,COUNT(DISTINCT p.id) product_count
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active'
             JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active'
             JOIN mc_brand b ON b.id=p.brand_id
             WHERE p.status='published'{$categorySql}
             GROUP BY b.id,b.name ORDER BY b.name ASC LIMIT 100",
            $params,
        );

        $priceParams = [$context->storeId, $context->marketId, $context->storeId, $context->marketId, $context->currency];

        $price = $this->connection->fetchAssociative(
            "SELECT MIN(pr.amount_minor) min_price,MAX(pr.amount_minor) max_price
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active'
             JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active'
             JOIN mc_product_variant v ON v.product_id=p.id AND v.status='active' AND v.sort_order=0
             LEFT JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
             WHERE p.status='published'{$categorySql}",
            $priceParams,
        );

        $attributeParams = [$context->storeId, $context->marketId, $context->locale, $context->locale];

        $attributeRows = $this->connection->fetchAllAssociative(
            "SELECT ad.code,ad.data_type,COALESCE(at.name,ad.code) attribute_name,at.unit_label,
                    CASE
                        WHEN ad.data_type IN ('boolean','bool') THEN CONCAT('b:',CAST(pav.value_boolean AS CHAR))
                        WHEN ad.data_type IN ('decimal','number') THEN CONCAT('d:',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(pav.value_decimal AS CHAR))))
                        ELSE CONCAT('t:',LOWER(HEX(pav.value_text_hash)))
                    END value_token,
                    CASE
                        WHEN ad.data_type IN ('boolean','bool') THEN IF(pav.value_boolean=1,'1','0')
                        WHEN ad.data_type IN ('decimal','number') THEN TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(pav.value_decimal AS CHAR)))
                        ELSE pav.value_text
                    END value_label,
                    COUNT(DISTINCT p.id) product_count,ad.sort_order
             FROM mc_product p
             JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active'
             JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active'
             JOIN mc_product_attribute_value pav ON pav.product_id=p.id AND pav.variant_id IS NULL AND (pav.locale IS NULL OR pav.locale=?)
             JOIN mc_attribute_definition ad ON ad.id=pav.attribute_id AND ad.filterable=1
             LEFT JOIN mc_attribute_translation at ON at.attribute_id=ad.id AND at.locale=?
             WHERE p.status='published'
               AND ((ad.data_type IN ('boolean','bool') AND pav.value_boolean IS NOT NULL)
                 OR (ad.data_type IN ('decimal','number') AND pav.value_decimal IS NOT NULL)
                 OR (ad.data_type NOT IN ('boolean','bool','decimal','number') AND pav.value_text IS NOT NULL AND pav.value_text_hash IS NOT NULL)){$categorySql}
             GROUP BY ad.id,ad.code,ad.data_type,at.name,at.unit_label,value_token,value_label,ad.sort_order
             ORDER BY ad.sort_order ASC,attribute_name ASC,product_count DESC,value_label ASC
             LIMIT 600",
            $attributeParams,
        );
        $attributeFacets = [];
        foreach ($attributeRows as $row) {
            $code = (string) $row['code'];
            if (!isset($attributeFacets[$code])) {
                if (count($attributeFacets) >= 12) {
                    continue;
                }
                $attributeFacets[$code] = [
                    'code' => $code,
                    'name' => (string) $row['attribute_name'],
                    'data_type' => (string) $row['data_type'],
                    'unit_label' => $row['unit_label'] !== null ? (string) $row['unit_label'] : null,
                    'values' => [],
                ];
            }
            if (count($attributeFacets[$code]['values']) >= 20) {
                continue;
            }
            $token = (string) $row['value_token'];
            $label = trim((string) $row['value_label']);
            if (in_array((string) $row['data_type'], ['boolean','bool'], true)) {
                $label = \Commerce\Core\I18n\CanonicalUiText::get($label === '1' ? 'catalog.boolean_yes' : 'catalog.boolean_no');
            }
            if ($token === '' || $label === '') {
                continue;
            }
            $attributeFacets[$code]['values'][] = [
                'token' => $token,
                'label' => $label,
                'count' => (int) $row['product_count'],
            ];
        }

        return [
            'brands' => array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'count'=>(int)$row['product_count']], $brands),
            'price_min_minor' => isset($price['min_price']) && $price['min_price'] !== null ? (int) $price['min_price'] : null,
            'price_max_minor' => isset($price['max_price']) && $price['max_price'] !== null ? (int) $price['max_price'] : null,
            'attributes' => array_values($attributeFacets),
        ];
    }

    /** @return list<string> */
    private function searchTokens(string $search): array
    {
        $search = trim(mb_substr($search, 0, 120, 'UTF-8'));
        if ($search === '') {
            return [];
        }
        $parts = preg_split('/[\s,;|]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_unique(array_filter(array_map(static fn(string $part): string => mb_substr(trim($part), 0, 50, 'UTF-8'), $parts), static fn(string $part): bool => mb_strlen($part, 'UTF-8') >= 1)));
        return array_slice($parts, 0, 8);
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /** @return array<string,mixed>|null */
    public function categoryByPublicId(StorefrontContext $context, string $publicId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT c.id,c.public_id,c.parent_id,ct.name,ct.h1,ct.description,ct.description_bottom,ct.meta_title,ct.meta_description,sr.path,cma.storage_key AS cover_key,ci.alt_text AS cover_alt FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active' JOIN mc_market_category mk ON mk.category_id=c.id AND mk.market_id=? AND mk.status='active' JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=? JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='category' AND sr.entity_public_id=c.public_id LEFT JOIN mc_category_image ci ON ci.category_id=c.id LEFT JOIN mc_media_asset cma ON cma.id=ci.asset_id WHERE c.public_id=? AND c.status='active' LIMIT 1",
            [$context->storeId,$context->marketId,$context->storeId,$context->locale,$context->storeId,$context->locale,Uuid::fromString($publicId)->toBinary()],
        );
        return is_array($row) ? $this->categoryRow($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function productByPublicId(StorefrontContext $context, string $publicId, ?string $selectedVariantPublicId = null): ?array
    {
        $selectedVariantBinary = null;
        if (is_string($selectedVariantPublicId) && trim($selectedVariantPublicId) !== '') {
            try {
                $selectedVariantBinary = Uuid::fromString(trim($selectedVariantPublicId))->toBinary();
            } catch (\Throwable) {
                $selectedVariantBinary = null;
            }
        }
        $row = $this->connection->fetchAssociative(
            "SELECT p.id,p.public_id,p.product_type,p.condition_code,p.country_of_origin,pt.name,pt.h1,pt.short_description,pt.description,pt.meta_title,pt.meta_description,
                    v.id AS variant_id,v.public_id AS variant_public_id,v.sku,v.gtin,v.mpn,v.sale_unit_code,v.quantity_step,v.min_order_quantity,v.max_order_quantity,v.allow_backorder,
                    pr.amount_minor,pr.compare_at_minor,pr.ends_at AS price_ends_at,pr.currency,pr.tax_included,sr.path,sr.indexable AS route_indexable,p.brand_id,COALESCE(b.name,'') AS brand_name,tr.rate_bps,
                    mtp.consumer_display_mode,COALESCE(ppp.mode,'auto') AS purchase_mode,ppp.button_label AS purchase_button_label,ppp.eta_text AS purchase_eta_text,
                    COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id),0) AS available_quantity
             FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active' JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active'
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=? JOIN mc_product_variant v ON v.product_id=p.id AND v.status='active' AND ((? IS NOT NULL AND v.public_id=?) OR (? IS NULL AND v.sort_order=0))
             JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='product' AND sr.entity_public_id=p.public_id
             LEFT JOIN mc_brand b ON b.id=p.brand_id
             LEFT JOIN mc_product_purchase_policy ppp ON ppp.product_id=p.id
             LEFT JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
             LEFT JOIN mc_tax_rate tr ON tr.id=(SELECT tx.id FROM mc_tax_rate tx WHERE tx.tax_class_id=p.tax_class_id AND tx.country_code=? AND tx.enabled=1 AND tx.valid_from<=UTC_TIMESTAMP(6) AND (tx.valid_to IS NULL OR tx.valid_to>UTC_TIMESTAMP(6)) ORDER BY tx.priority ASC,tx.id DESC LIMIT 1)
             LEFT JOIN mc_market_tax_policy mtp ON mtp.market_id=?
             WHERE p.public_id=? AND p.status='published' AND (sp.published_at IS NULL OR sp.published_at<=UTC_TIMESTAMP(6)) AND (mp.published_at IS NULL OR mp.published_at<=UTC_TIMESTAMP(6)) LIMIT 1",
            [$context->marketId,$context->storeId,$context->marketId,$context->storeId,$context->locale,$selectedVariantBinary,$selectedVariantBinary,$selectedVariantBinary,$context->storeId,$context->locale,$context->storeId,$context->marketId,$context->currency,$context->countryCode,$context->marketId,Uuid::fromString($publicId)->toBinary()],
        );
        if (!is_array($row) || $row['amount_minor'] === null) { return null; }

        $product = $this->badges->decorate([$this->productCardRow($row, $context)], $context->storeId, $context->locale)[0];
        $product['description'] = (string) ($row['description'] ?? '');
        $product['short_description'] = (string) ($row['short_description'] ?? '');
        $product['gtin'] = $row['gtin'] ?: null; $product['mpn'] = $row['mpn'] ?: null;
        $product['condition'] = 'https://schema.org/NewCondition';
        $product['country_of_origin'] = $row['country_of_origin'] ?: null;
        $product['tax']['display_mode'] = (string) ($row['consumer_display_mode'] ?: 'price_only');
        $product['images'] = $this->productImages((int)$row['id'], (string)$row['name'], (string)($row['brand_name'] ?? ''), (string)($row['sku'] ?? ''));
        $product['gallery'] = $this->gallery((int)$row['id'], (string)$row['name'], $product['images']);
        // The detail query has no card image column: use the first gallery image (feeds JSON-LD, sharing and the "recently viewed" cards).
        if (($product['images'][0]['url'] ?? '') !== '') { $product['image'] = (string) $product['images'][0]['url']; }
        $product['attributes'] = $this->productAttributes((int)$row['id'], $context->locale);
        $product['features'] = array_map(static fn(array $a): string => $a['name'] . ': ' . $a['value'], array_slice($product['attributes'], 0, 12));
        $product['documents'] = $this->productDocuments((int)$row['id'], $context->locale);
        $product['rating'] = $this->rating((int)$row['id']);
        $product['reviews'] = $this->reviews((int)$row['id'], $context);
        $product['breadcrumbs'] = $this->breadcrumbs((int)$row['id'], $context, (string)$row['name']);
        $product['variants'] = $this->productVariants((int) $row['id'], $context, (int) $row['variant_id']);
        $product['option_groups'] = $product['variants'] === [] ? [] : $this->optionGroups((int) $row['id'], $context->locale);
        $product['addons'] = $this->addons?->forStorefront((int) $row['id'], $context->storeId, $context->locale, $context->currency) ?? [];
        $product['questions'] = $this->questions((int)$row['id'], $context);
        $product['related'] = $this->relationProducts((int)$row['id'], $context, 'related');
        $product['complementary'] = $this->relationProducts((int)$row['id'], $context, 'complementary');
        $product['compliance'] = [];
        $product['shipping'] = [];
        $product['return_policy'] = [];
        return $product;
    }

    /** @return list<array{id:string,label:string,sku:string,price:string,selected:bool,available:bool}> */
    private function productVariants(int $productId, StorefrontContext $context, int $selectedVariantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT v.id,v.public_id,v.sku,pr.amount_minor,pr.compare_at_minor,pr.currency,
                    COALESCE(NULLIF((SELECT GROUP_CONCAT(COALESCE(ovt.name,(SELECT x.name FROM mc_product_option_value_translation x WHERE x.option_value_id=ov.id ORDER BY x.locale LIMIT 1),ov.code) ORDER BY po.sort_order,ov.sort_order SEPARATOR ' / ') FROM mc_variant_option_value vov JOIN mc_product_option_value ov ON ov.id=vov.option_value_id JOIN mc_product_option po ON po.id=ov.option_id LEFT JOIN mc_product_option_value_translation ovt ON ovt.option_value_id=ov.id AND ovt.locale=? WHERE vov.variant_id=v.id),''),v.sku) label,
                    COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id),0) available_quantity,
                    p.product_type,v.allow_backorder
             FROM mc_product_variant v
             JOIN mc_product p ON p.id=v.product_id
             JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
             WHERE v.product_id=? AND v.status='active' ORDER BY v.sort_order,v.id",
            [$context->locale,$context->marketId,$context->storeId,$context->marketId,$context->currency,$productId],
        );
        if (count($rows) <= 1) {
            return [];
        }
        $swatches = $this->variantSwatches(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $valueMap = $this->variantOptionValues(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        return array_map(function (array $row) use ($context, $selectedVariantId, $swatches, $valueMap): array {
            $available = (string) $row['product_type'] === 'digital' || (float) $row['available_quantity'] > 0 || (bool) $row['allow_backorder'];
            $amount = (int) $row['amount_minor'];
            $fixedVariantPrice = $context->customerGroup !== 'default' ? $this->groupFixedPrice((string) $row['public_id'], $context) : null;
            if ($fixedVariantPrice !== null && $fixedVariantPrice < $amount) {
                $amount = $fixedVariantPrice;
            } elseif ($context->groupDiscountBps > 0 && $amount > 0 && !($context->groupSkipsSale && (int) ($row['compare_at_minor'] ?? 0) > $amount)) {
                $amount = intdiv($amount * (10000 - min(9000, $context->groupDiscountBps)) + 5000, 10000);
            }
            return [
                'id' => Uuid::fromBinary((string) $row['public_id'])->toRfc4122(),
                'swatch' => $swatches[(int) $row['id']] ?? '',
                'label' => (string) $row['label'],
                'values' => $valueMap[(int) $row['id']] ?? [],
                'sku' => (string) $row['sku'],
                'price' => $this->money->format($amount, (string) $row['currency'], $context->locale),
                'price_minor' => $amount,
                'currency' => (string) $row['currency'],
                'selected' => (int) $row['id'] === $selectedVariantId,
                'available' => $available,
            ];
        }, $rows);
    }

    /**
     * Option value ids of every variant, keyed by option id: [variantId => [optionId => valueId]].
     *
     * @param list<int> $variantIds
     * @return array<int,array<int,int>>
     */
    private function variantOptionValues(array $variantIds): array
    {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $result = [];
        foreach ($this->connection->fetchAllAssociative("SELECT vov.variant_id,ov.option_id,ov.id value_id FROM mc_variant_option_value vov JOIN mc_product_option_value ov ON ov.id=vov.option_value_id WHERE vov.variant_id IN ({$placeholders})", $variantIds) as $row) {
            $result[(int) $row['variant_id']][(int) $row['option_id']] = (int) $row['value_id'];
        }

        return $result;
    }

    /**
     * The option pickers of a product: [{id,name,values:[{id,label,swatch,media_id}]}] (only options that have values).
     *
     * @return list<array{id:int,name:string,values:list<array{id:int,label:string,swatch:string,media_id:?int}>}>
     */
    private function optionGroups(int $productId, string $locale): array
    {
        $options = $this->connection->fetchAllAssociative(
            'SELECT po.id,po.display,COALESCE(pot.name,(SELECT x.name FROM mc_product_option_translation x WHERE x.option_id=po.id ORDER BY x.locale LIMIT 1),po.code) name FROM mc_product_option po LEFT JOIN mc_product_option_translation pot ON pot.option_id=po.id AND pot.locale=? WHERE po.product_id=? ORDER BY po.sort_order,po.id',
            [$locale, $productId],
        );
        $groups = [];
        foreach ($options as $option) {
            $values = $this->connection->fetchAllAssociative(
                'SELECT ov.id,ov.swatch,ov.media_asset_id,COALESCE(ovt.name,(SELECT x.name FROM mc_product_option_value_translation x WHERE x.option_value_id=ov.id ORDER BY x.locale LIMIT 1),ov.code) label FROM mc_product_option_value ov LEFT JOIN mc_product_option_value_translation ovt ON ovt.option_value_id=ov.id AND ovt.locale=? WHERE ov.option_id=? ORDER BY ov.sort_order,ov.id',
                [$locale, (int) $option['id']],
            );
            if ($values === []) {
                continue;
            }
            $groups[] = ['id' => (int) $option['id'], 'name' => (string) $option['name'], 'display' => (string) ($option['display'] ?? 'buttons'), 'values' => array_map(static fn (array $v): array => ['id' => (int) $v['id'], 'label' => (string) $v['label'], 'swatch' => (string) ($v['swatch'] ?? ''), 'media_id' => $v['media_asset_id'] !== null ? (int) $v['media_asset_id'] : null], $values)];
        }

        return $groups;
    }

    /**
     * A variant whose only option value is a colour (the option value code is a CSS hex colour or a basic
     * colour keyword) is rendered as a colour swatch; every other variant is rendered as a text chip.
     *
     * @param list<int> $variantIds
     * @return array<int,string>
     */
    private function variantSwatches(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $rows = $this->connection->fetchAllAssociative(
            "SELECT vov.variant_id,ov.code FROM mc_variant_option_value vov JOIN mc_product_option_value ov ON ov.id=vov.option_value_id WHERE vov.variant_id IN ({$placeholders})",
            $variantIds,
        );
        $byVariant = [];
        foreach ($rows as $row) {
            $byVariant[(int) $row['variant_id']][] = strtolower(trim((string) $row['code']));
        }
        $keywords = ['black', 'white', 'red', 'green', 'blue', 'yellow', 'orange', 'purple', 'pink', 'brown', 'gray', 'grey', 'silver', 'gold', 'navy', 'beige', 'teal', 'cyan', 'magenta', 'maroon', 'olive', 'lime'];
        $result = [];
        foreach ($byVariant as $variantId => $codes) {
            if (count($codes) !== 1) {
                continue;
            }
            $code = $codes[0];
            if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/D', $code) === 1 || in_array($code, $keywords, true)) {
                $result[$variantId] = $code;
            }
        }
        return $result;
    }

    /** @return array{variant_id:int,product_id:int,variant_public_id:string,product_type:string,name:string,sku:string,price_minor:int,currency:string,unit_code:string,quantity_step:string,min_quantity:string,max_quantity:?string,available_quantity:string,allow_backorder?:bool}|null */
    public function purchasableVariant(StorefrontContext $context, string $variantPublicId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT v.id,v.product_id,v.public_id,v.sku,p.product_type,v.sale_unit_code,v.quantity_step,v.min_order_quantity,v.max_order_quantity,v.allow_backorder,v.manage_inventory,pt.name,pr.amount_minor,pr.currency,
                    COALESCE(ppp.mode,'auto') AS purchase_mode,
                    COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=v.id),0) AS available_quantity
             FROM mc_product_variant v JOIN mc_product p ON p.id=v.product_id AND p.status='published' JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active' JOIN mc_market_product mp ON mp.product_id=p.id AND mp.market_id=? AND mp.status='active' JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=?
             LEFT JOIN mc_product_purchase_policy ppp ON ppp.product_id=p.id
             JOIN mc_price pr ON pr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=v.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.max_quantity IS NULL OR px.max_quantity>=1) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
             WHERE v.public_id=? AND v.status='active' LIMIT 1",
            [$context->marketId,$context->storeId,$context->marketId,$context->storeId,$context->locale,$context->storeId,$context->marketId,$context->currency,Uuid::fromString($variantPublicId)->toBinary()],
        );
        if (!is_array($row)) { return null; }
        $mode = (string) ($row['purchase_mode'] ?? 'auto');
        if (in_array($mode, ['coming_soon','sold_out','notify','price_request'], true)) {
            return null;
        }
        $allowBackorder = (bool) ($row['allow_backorder'] ?? false) || in_array($mode, ['backorder','preorder'], true) || ((int) ($row['manage_inventory'] ?? 1) === 0 && (string) $row['product_type'] === 'physical'); /* stock is not tracked: nothing to oversell */
        return ['variant_id'=>(int)$row['id'],'product_id'=>(int)$row['product_id'],'variant_public_id'=>Uuid::fromBinary((string)$row['public_id'])->toRfc4122(),'product_type'=>(string)$row['product_type'],'name'=>(string)$row['name'],'sku'=>(string)$row['sku'],'price_minor'=>(int)$row['amount_minor'],'currency'=>(string)$row['currency'],'unit_code'=>(string)$row['sale_unit_code'],'quantity_step'=>(string)$row['quantity_step'],'min_quantity'=>(string)$row['min_order_quantity'],'max_quantity'=>$row['max_order_quantity']!==null?(string)$row['max_order_quantity']:null,'available_quantity'=>(string)$row['available_quantity'],'allow_backorder'=>$allowBackorder];
    }

    private function productCardRow(array $row, StorefrontContext $context): array
    {
        $priceMinor = (int)($row['amount_minor'] ?? 0); $compareMinor = $row['compare_at_minor'] !== null ? (int)$row['compare_at_minor'] : null; if ($compareMinor !== null && $compareMinor <= $priceMinor) { $compareMinor = null; } /* an "old price" that is not higher than the price is no discount */ $rate = (int)($row['rate_bps'] ?? 0);
        $fixedGroupPrice = $context->customerGroup !== 'default' ? $this->groupFixedPrice((string) ($row['variant_public_id'] ?? ''), $context) : null;
        if ($fixedGroupPrice !== null && $fixedGroupPrice < $priceMinor) { /* an own price of the customer group beats the percentage */ $compareMinor = max($compareMinor ?? 0, $priceMinor); $priceMinor = $fixedGroupPrice; }
        elseif ($context->groupDiscountBps > 0 && $priceMinor > 0 && !($context->groupSkipsSale && $compareMinor !== null)) { /* customer-group price: the regular price becomes the "old" one */ $compareMinor = max($compareMinor ?? 0, $priceMinor); $priceMinor = intdiv($priceMinor * (10000 - min(9000, $context->groupDiscountBps)) + 5000, 10000); }
        $taxMinor = $rate > 0 ? $priceMinor - intdiv(($priceMinor * 10000) + intdiv(10000 + $rate,2),10000+$rate) : 0;
        $mode = $this->taxDisplayMode((int) $context->marketId);
        $showNet = in_array($mode, ['net', 'net_with_gross'], true);
        $netCompare = $compareMinor !== null ? $compareMinor - ($rate > 0 ? $compareMinor - intdiv(($compareMinor * 10000) + intdiv(10000 + $rate,2),10000+$rate) : 0) : null;
        return [
            'id'=>Uuid::fromBinary((string)$row['public_id'])->toRfc4122(), 'internal_id'=>(int)$row['id'], 'variant_id'=>Uuid::fromBinary((string)$row['variant_public_id'])->toRfc4122(),
            'product_type'=>(string)($row['product_type'] ?? 'physical'), 'name'=>(string)$row['name'], 'h1'=>(string)($row['h1']??''), 'meta_title'=>(string)($row['meta_title']??''), 'meta_description'=>(string)($row['meta_description']??''), 'indexable'=>(bool)($row['route_indexable']??1), 'brand'=>(string)($row['brand_name']??''), 'brand_id'=>isset($row['brand_id']) && $row['brand_id'] !== null ? (int)$row['brand_id'] : null, 'sku'=>(string)$row['sku'], 'url'=>'/'.ltrim((string)$row['path'],'/'),
            'price'=>$this->money->format($showNet?$priceMinor-$taxMinor:$priceMinor,(string)$row['currency'],$context->locale), 'price_minor'=>$priceMinor,
            'compare_at_price'=>$compareMinor!==null?$this->money->format($showNet?(int)$netCompare:$compareMinor,(string)$row['currency'],$context->locale):null, 'discount_percent'=>($compareMinor!==null && $compareMinor>$priceMinor && $compareMinor>0)?max(1,min(99,(int)round((1-$priceMinor/$compareMinor)*100))):0, 'currency'=>(string)$row['currency'], 'gross_price'=>number_format($priceMinor/100,2,'.',''), 'merchant_price'=>number_format($priceMinor/100,2,'.',''),
            'sale_ends_at'=>($compareMinor!==null && $compareMinor>$priceMinor && !empty($row['price_ends_at']))?(new \DateTimeImmutable((string)$row['price_ends_at'],new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'):null,
            'image'=>$this->mediaUrl($row['image_key']??null), ...$this->purchaseState($row), 'available_quantity'=>(string)$row['available_quantity'],
            'quantity'=>['unit_code'=>(string)$row['sale_unit_code'],'unit_label'=>(string)$row['sale_unit_code'],'step'=>$this->trimDecimal((string)$row['quantity_step']),'min'=>$this->trimDecimal((string)$row['min_order_quantity']),'max'=>$row['max_order_quantity']!==null?$this->trimDecimal((string)$row['max_order_quantity']):null],
            'rating'=>['value'=>round((float)($row['rating_value'] ?? 0),1),'count'=>(int)($row['review_count'] ?? 0)],
            'tax'=>['display_mode'=>$mode,'rate_label'=>$rate>0?($rate/100).'%':'0%','tax_amount'=>$this->money->format($taxMinor,(string)$row['currency'],$context->locale),'net_price'=>$this->money->format($priceMinor-$taxMinor,(string)$row['currency'],$context->locale),'gross_price'=>$this->money->format($priceMinor,(string)$row['currency'],$context->locale)],
        ];
    }

    private function groupFixedPrice(string $variantPublicIdBinary, StorefrontContext $context): ?int
    {
        static $memo = [];
        if ($variantPublicIdBinary === '') {
            return null;
        }
        $key = bin2hex($variantPublicIdBinary) . '|' . $context->customerGroup . '|' . $context->currency . '|' . $context->storeId;
        if (!array_key_exists($key, $memo)) {
            try {
                $v = $this->connection->fetchOne(
                    "SELECT px.amount_minor FROM mc_price px JOIN mc_product_variant v ON v.id=px.variant_id WHERE v.public_id=? AND px.store_id=? AND px.currency=? AND px.customer_group=? AND px.price_list_id IS NULL AND px.min_quantity<=1 AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY px.id DESC LIMIT 1",
                    [$variantPublicIdBinary, $context->storeId, $context->currency, $context->customerGroup],
                );
            } catch (\Throwable) {
                $v = false;
            }
            $memo[$key] = $v === false ? null : (int) $v;
        }

        return $memo[$key];
    }

    /** @return array<int,true> physical products whose stock is not tracked: always available, never oversold */
    private function untrackedProducts(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = [];
            try {
                foreach ($this->connection->fetchFirstColumn("SELECT DISTINCT v.product_id FROM mc_product_variant v JOIN mc_product p ON p.id=v.product_id WHERE v.manage_inventory=0 AND p.product_type='physical'") as $id) {
                    $ids[(int) $id] = true;
                }
            } catch (\Throwable) {
                $ids = [];
            }
        }

        return $ids;
    }

    private function taxDisplayMode(int $marketId): string
    {
        static $cache = [];
        if (!isset($cache[$marketId])) {
            try {
                $mode = (string) $this->connection->fetchOne('SELECT consumer_display_mode FROM mc_market_tax_policy WHERE market_id=?', [$marketId]);
            } catch (\Throwable) {
                $mode = '';
            }
            $cache[$marketId] = in_array($mode, ['price_only', 'gross_with_breakdown', 'net_with_gross', 'net'], true) ? $mode : 'price_only';
        }

        return $cache[$marketId];
    }

    /** @return array{availability_label:string,availability:string,purchase_mode:string,purchase_allowed:bool,purchase_button_label:string,purchase_eta_text:?string,notify_available:bool,price_request:bool} */
    private function purchaseState(array $row): array
    {
        $digital = (string) ($row['product_type'] ?? 'physical') === 'digital' || isset($this->untrackedProducts()[(int) ($row['id'] ?? 0)]);
        $stock = (float) ($row['available_quantity'] ?? 0);
        $mode = (string) ($row['purchase_mode'] ?? 'auto');
        $variantBackorder = (bool) ($row['allow_backorder'] ?? false);
        $customButton = trim((string) ($row['purchase_button_label'] ?? ''));
        $eta = trim((string) ($row['purchase_eta_text'] ?? ''));

        $label = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.nemaie_v_naiavnosti');
        $schema = 'https://schema.org/OutOfStock';
        $allowed = false;
        $notify = false;
        $priceRequest = false;
        $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.do_koshyka');

        if ($mode === 'backorder') {
            $label = $eta !== '' ? \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.pid_zamovlennia') . $eta : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pid_zamovlennia');
            $schema = 'https://schema.org/BackOrder';
            $allowed = true;
            $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.zamovyty');
        } elseif ($mode === 'preorder') {
            $label = $eta !== '' ? \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.peredzamovlennia') . $eta : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.peredzamovlennia_2');
            $schema = 'https://schema.org/PreOrder';
            $allowed = true;
            $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.peredzamovyty');
        } elseif ($mode === 'coming_soon') {
            $label = $eta !== '' ? \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.ochikuiemo') . $eta : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.ochikuiemo_nadkhodzhennia');
            $notify = true;
            $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.povidomyty_pro_naiavnist');
        } elseif ($mode === 'sold_out') {
            $label = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.prodano');
            $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.prodano');
        } elseif ($mode === 'notify') {
            $label = $eta !== '' ? \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.nemaie_v_naiavnosti_2') . $eta : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.nemaie_v_naiavnosti');
            $notify = true;
            $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.povidomyty_pro_naiavnist');
        } elseif ($mode === 'price_request') {
            $label = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.tsina_za_zapytom');
            $priceRequest = true;
            $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.zapytaty_tsinu');
        } elseif ($digital || $stock > 0) {
            $label = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.v_naiavnosti');
            $schema = 'https://schema.org/InStock';
            $allowed = true;
        } elseif ($variantBackorder) {
            $label = $eta !== '' ? \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.pid_zamovlennia') . $eta : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.pid_zamovlennia');
            $schema = 'https://schema.org/BackOrder';
            $allowed = true;
            $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.zamovyty');
        } else {
            $notify = true;
            $button = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoseeder.povidomyty_pro_naiavnist');
        }

        if ($customButton !== '') {
            $button = mb_substr($customButton, 0, 120, 'UTF-8');
        }

        return [
            'availability_label' => $label,
            'availability' => $schema,
            'purchase_mode' => $mode,
            'purchase_allowed' => $allowed,
            'purchase_button_label' => $button,
            'purchase_eta_text' => $eta !== '' ? $eta : null,
            'notify_available' => $notify,
            'price_request' => $priceRequest,
        ];
    }

    private function categoryRow(array $row): array
    {
        return ['id'=>(int)$row['id'],'public_id'=>Uuid::fromBinary((string)$row['public_id'])->toRfc4122(),'name'=>(string)$row['name'],'h1'=>(string)($row['h1']??''),'description'=>(string)($row['description']??''),'description_bottom'=>(string)($row['description_bottom']??''),'meta_title'=>(string)($row['meta_title']??''),'meta_description'=>(string)($row['meta_description']??''),'url'=>'/'.ltrim((string)$row['path'],'/'),'image'=>$this->mediaUrl($row['image_key']??null),'cover'=>isset($row['cover_key'])&&is_string($row['cover_key'])&&$row['cover_key']!==''?$this->mediaUrl($row['cover_key']):null,'cover_alt'=>(string)($row['cover_alt']??'')];
    }

    /** @return list<array<string,mixed>> */
    private function relationProducts(int $productId, StorefrontContext $context, string $relationType): array
    {
        $limit = 8;
        $rows = $this->connection->fetchAllAssociative(
            "SELECT rp.id,rp.public_id,rpt.name,rv.public_id variant_public_id,rv.sku,rr.amount_minor,rr.compare_at_minor,rr.currency,rsr.path,
                    COALESCE((SELECT ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=rp.id AND pm.role IN ('primary','gallery') ORDER BY (pm.role='primary') DESC,pm.sort_order LIMIT 1),'') image_key,
                    COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=rv.id),0) available_quantity
             FROM mc_product_relation rel
             JOIN mc_product rp ON rp.id=rel.related_product_id AND rp.status='published'
             JOIN mc_store_product rsp ON rsp.product_id=rp.id AND rsp.store_id=? AND rsp.status='active'
             JOIN mc_market_product rmp ON rmp.product_id=rp.id AND rmp.market_id=? AND rmp.status='active'
             JOIN mc_product_translation rpt ON rpt.product_id=rp.id AND rpt.store_id=? AND rpt.locale=?
             JOIN mc_product_variant rv ON rv.product_id=rp.id AND rv.status='active' AND rv.sort_order=0
             JOIN mc_seo_route rsr ON rsr.store_id=? AND rsr.locale=? AND rsr.entity_type='product' AND rsr.entity_public_id=rp.public_id
             LEFT JOIN mc_price rr ON rr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=rv.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
             WHERE rel.product_id=? AND rel.relation_type=? ORDER BY rel.sort_order,rel.related_product_id LIMIT {$limit}",
            [$context->marketId,$context->storeId,$context->marketId,$context->storeId,$context->locale,$context->storeId,$context->locale,$context->storeId,$context->marketId,$context->currency,$productId,$relationType],
        );

        $manualIds = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $mode = 'hybrid';
        $autoLimit = $limit;
        $inStockOnly = false;
        try {
            $settings = $this->connection->fetchAssociative('SELECT related_mode,complementary_mode,auto_limit,in_stock_only FROM mc_recommendation_setting WHERE store_id=?', [$context->storeId]);
            if (is_array($settings)) {
                $mode = (string)($relationType === 'complementary' ? $settings['complementary_mode'] : $settings['related_mode']);
                $autoLimit = max(1, min($limit, (int)$settings['auto_limit']));
                $inStockOnly = (bool)$settings['in_stock_only'];
            }
        } catch (\Throwable) {
            // Migration-safe fallback: manual relations continue to work before the recommendation settings table exists.
        }

        if ($mode === 'auto') {
            $rows = [];
            $manualIds = [];
        }
        if ($mode !== 'manual' && count($rows) < $limit) {
            $needed = min($autoLimit, $limit - count($rows));
            $autoIds = $this->automaticRecommendationIds($productId, $context, $relationType, $needed, $manualIds, $inStockOnly);
            if ($autoIds !== []) {
                $rows = array_merge($rows, $this->recommendationRowsForIds($autoIds, $context));
            }
        }

        return array_map(fn(array $r): array => [
            'id'=>Uuid::fromBinary((string)$r['public_id'])->toRfc4122(),
            'variant_id'=>Uuid::fromBinary((string)$r['variant_public_id'])->toRfc4122(),
            'name'=>(string)$r['name'],'sku'=>(string)$r['sku'],'url'=>'/'.ltrim((string)$r['path'],'/'),
            'price'=>$this->money->format((int)$r['amount_minor'],(string)$r['currency'],$context->locale),
            'currency'=>(string)$r['currency'],'image'=>$this->mediaUrl($r['image_key']??null),
            'availability_label'=>\Commerce\Core\I18n\CanonicalUiText::get((float)$r['available_quantity']>0?'catalog.in_stock':'catalog.out_of_stock'),
            'purchase_allowed'=>(float)$r['available_quantity']>0,
        ], array_slice($rows, 0, $limit));
    }

    /** @param list<int> $exclude @return list<int> */
    private function automaticRecommendationIds(int $productId, StorefrontContext $context, string $relationType, int $limit, array $exclude, bool $inStockOnly): array
    {
        if ($limit <= 0) {
            return [];
        }
        $excluded = array_values(array_unique(array_merge([$productId], array_map('intval', $exclude))));
        $notIn = implode(',', array_fill(0, count($excluded), '?'));

        if ($relationType === 'complementary') {
            $sql = "SELECT oi2.product_id,COUNT(DISTINCT oi1.order_id) score
                    FROM mc_sales_order_item oi1
                    JOIN mc_sales_order o ON o.id=oi1.order_id AND o.store_id=? AND o.status NOT IN ('cancelled','expired','rejected')
                    JOIN mc_sales_order_item oi2 ON oi2.order_id=oi1.order_id AND oi2.product_id IS NOT NULL AND oi2.product_id<>oi1.product_id
                    JOIN mc_product p2 ON p2.id=oi2.product_id AND p2.status='published'
                    JOIN mc_store_product sp2 ON sp2.product_id=p2.id AND sp2.store_id=? AND sp2.status='active'
                    JOIN mc_market_product mp2 ON mp2.product_id=p2.id AND mp2.market_id=? AND mp2.status='active'
                    WHERE oi1.product_id=? AND oi2.product_id NOT IN ({$notIn})
                    GROUP BY oi2.product_id ORDER BY score DESC,oi2.product_id DESC LIMIT {$limit}";
            $params = [$context->storeId,$context->storeId,$context->marketId,$productId,...$excluded];
            $ids = array_map('intval', $this->connection->fetchFirstColumn($sql, $params));
        } else {
            $sql = "SELECT p2.id,
                           (CASE WHEN p2.brand_id=p1.brand_id AND p1.brand_id IS NOT NULL THEN 6 ELSE 0 END)
                         + (SELECT COUNT(*)*10 FROM mc_product_category c1 JOIN mc_product_category c2 ON c2.category_id=c1.category_id WHERE c1.product_id=p1.id AND c2.product_id=p2.id)
                         + (SELECT COUNT(*)*2 FROM mc_product_attribute_value a1 JOIN mc_product_attribute_value a2 ON a2.attribute_id=a1.attribute_id AND a2.product_id=p2.id
                            WHERE a1.product_id=p1.id AND COALESCE(a1.value_text,'')=COALESCE(a2.value_text,'') AND COALESCE(a1.value_decimal,-999999)=COALESCE(a2.value_decimal,-999999) AND COALESCE(a1.value_boolean,-1)=COALESCE(a2.value_boolean,-1)) score
                    FROM mc_product p1
                    JOIN mc_product p2 ON p2.id NOT IN ({$notIn}) AND p2.status='published'
                    JOIN mc_store_product sp2 ON sp2.product_id=p2.id AND sp2.store_id=? AND sp2.status='active'
                    JOIN mc_market_product mp2 ON mp2.product_id=p2.id AND mp2.market_id=? AND mp2.status='active'
                    WHERE p1.id=?
                    HAVING score>0
                    ORDER BY score DESC,p2.id DESC LIMIT {$limit}";
            $params = [...$excluded,$context->storeId,$context->marketId,$productId];
            $ids = array_map('intval', $this->connection->fetchFirstColumn($sql, $params));
        }

        if (!$inStockOnly || $ids === []) {
            return $ids;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stocked = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT DISTINCT v.product_id FROM mc_product_variant v JOIN mc_variant_inventory_item vii ON vii.variant_id=v.id JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE v.product_id IN ({$placeholders}) AND (sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock)>0",
            [$context->marketId,...$ids],
        ));
        $set = array_fill_keys($stocked, true);
        return array_values(array_filter($ids, static fn(int $id): bool => isset($set[$id])));
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function recommendationRowsForIds(array $ids, StorefrontContext $context): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->connection->fetchAllAssociative(
            "SELECT rp.id,rp.public_id,rpt.name,rv.public_id variant_public_id,rv.sku,rr.amount_minor,rr.compare_at_minor,rr.currency,rsr.path,
                    COALESCE((SELECT ma.storage_key FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=rp.id AND pm.role IN ('primary','gallery') ORDER BY (pm.role='primary') DESC,pm.sort_order LIMIT 1),'') image_key,
                    COALESCE((SELECT SUM(GREATEST(sl.stocked_quantity-sl.reserved_quantity-sl.safety_stock,0)) FROM mc_variant_inventory_item vii JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id JOIN mc_market_inventory_location mil ON mil.location_id=sl.location_id AND mil.market_id=? WHERE vii.variant_id=rv.id),0) available_quantity
             FROM mc_product rp
             JOIN mc_store_product rsp ON rsp.product_id=rp.id AND rsp.store_id=? AND rsp.status='active'
             JOIN mc_market_product rmp ON rmp.product_id=rp.id AND rmp.market_id=? AND rmp.status='active'
             JOIN mc_product_translation rpt ON rpt.product_id=rp.id AND rpt.store_id=? AND rpt.locale=?
             JOIN mc_product_variant rv ON rv.product_id=rp.id AND rv.status='active' AND rv.sort_order=0
             JOIN mc_seo_route rsr ON rsr.store_id=? AND rsr.locale=? AND rsr.entity_type='product' AND rsr.entity_public_id=rp.public_id
             LEFT JOIN mc_price rr ON rr.id=(SELECT px.id FROM mc_price px WHERE px.variant_id=rv.id AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1)
             WHERE rp.id IN ({$placeholders})",
            [$context->marketId,$context->storeId,$context->marketId,$context->storeId,$context->locale,$context->storeId,$context->locale,$context->storeId,$context->marketId,$context->currency,...$ids],
        );
        $byId = [];
        foreach ($rows as $row) {
            if ($row['amount_minor'] !== null) {
                $byId[(int)$row['id']] = $row;
            }
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }

    /**
     * Photos of a product. "url"/"srcset" are the page-size variants (product, zoom), "thumb" is the small one for the strip
     * under the gallery and "full" is the stored master used by the full-screen viewer; all variants are made on demand.
     *
     * @return list<array{url:string,alt:string,srcset:string,sizes:string,width:int,height:int,thumb:string,full:string}>
     */
    private function productImages(int $productId, string $name, string $brand = '', string $sku = ''): array
    {
        $rows=$this->connection->fetchAllAssociative("SELECT ma.id media_id,ma.storage_key,ma.width,ma.height,pm.alt_text,pm.sort_order,pm.role FROM mc_product_media pm JOIN mc_media_asset ma ON ma.id=pm.media_asset_id WHERE pm.product_id=? AND pm.role IN ('primary','gallery') ORDER BY (pm.role='primary') DESC,pm.sort_order ASC",[$productId]);
        if ($rows===[]) { return [['url'=>'/assets/product-placeholder.svg','alt'=>$name,'srcset'=>'','sizes'=>'(max-width: 900px) 100vw, 50vw','width'=>640,'height'=>640,'thumb'=>'/assets/product-placeholder.svg','full'=>'/assets/product-placeholder.svg']]; }
        $lowest=(int)min(array_map(static fn(array $r):int=>(int)$r['sort_order'],$rows));
        $position=0;
        return array_map(function(array $r) use ($name,$lowest,$brand,$sku,&$position): array {
            ++$position;
            $master=$this->mediaUrl($r['storage_key']);
            return [
                'media_id'=>(int)$r['media_id'],
                'url'=>$this->variants->url($master,'product'),
                'alt'=>(string)($r['alt_text']?:($this->imageAlt?->fallback($name,$position,$brand,$sku)?:$name)),
                'srcset'=>$this->variants->srcset($master,['product','zoom']),
                'avif_srcset'=>$this->variants->avifEnabled()?$this->variants->srcset($master,['product','zoom'],'avif'):'',
                'sort_order'=>$r['role']==='primary'?$lowest:(int)$r['sort_order'],
                'sizes'=>'(max-width: 900px) 100vw, 50vw',
                'width'=>(int)($r['width']?:1200),
                'height'=>(int)($r['height']?:1200),
                'thumb'=>$this->variants->url($master,'thumb'),
                'full'=>$this->variants->url($master,'zoom'),
            ];
        },$rows);
    }

    /**
     * Gallery of the product page: photos plus videos. Videos placed at the start come first, the others follow the photos.
     * "images" stays photos only (cards, structured data and sharing never use a video preview).
     *
     * @param list<array<string,mixed>> $images
     * @return list<array<string,mixed>>
     */
    private function gallery(int $productId, string $name, array $images): array
    {
        $videos = $this->videos->forStorefront($productId, $name);
        $placeholder = ($images[0]['url'] ?? '') === '/assets/product-placeholder.svg';
        $photos = $placeholder && $videos !== [] ? [] : array_map(static fn (array $i): array => $i + ['type' => 'image'], $images);
        // One shared order: a video sits where the merchant dragged it between the photos. Equal places: videos first, then photos in their own order.
        $merged = array_merge(array_map(static fn (array $v): array => $v + ['_k' => 0], $videos), array_map(static fn (array $p): array => $p + ['_k' => 1], $photos));
        usort($merged, static fn (array $a, array $b): int => [(int) ($a['sort_order'] ?? 0), $a['_k']] <=> [(int) ($b['sort_order'] ?? 0), $b['_k']]);

        return array_map(static function (array $item): array {
            unset($item['_k']);

            return $item;
        }, $merged);
    }

    /** @return list<array{name:string,value:string}> */
    private function productAttributes(int $productId,string $locale):array
    {
        // Values are stored per language: one value per attribute is shown, the visitor's language first, then language-neutral, then any.
        $sql=preg_replace('/^SELECT /','SELECT ad.id aid,pav.locale vloc,',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.select_coalesce_at_name_ad_code_name_coalesce_pav_va'),1);
        $rows=$this->connection->fetchAllAssociative((string)$sql,[$locale,$productId]);
        $chosen=[];
        foreach($rows as $r){
            if((string)$r['value']==='')continue;
            $rank=(string)$r['vloc']===$locale?0:($r['vloc']===null?1:2);
            $key=(int)$r['aid'];
            if(!isset($chosen[$key])||$rank<$chosen[$key]['rank'])$chosen[$key]=['rank'=>$rank,'name'=>(string)$r['name'],'value'=>(string)$r['value']];
        }
        return array_values(array_map(static fn(array $c):array=>['name'=>$c['name'],'value'=>$c['value']],$chosen));
    }

    /** @return list<array{name:string,url:string}> */
    private function productDocuments(int $productId,string $locale):array
    {
        $rows=$this->connection->fetchAllAssociative("SELECT pd.title,pd.document_type,ma.storage_key FROM mc_product_document pd JOIN mc_media_asset ma ON ma.id=pd.media_id WHERE pd.product_id=? AND pd.visible=1 AND (pd.locale=? OR pd.locale IS NULL) ORDER BY pd.sort_order,pd.id",[$productId,$locale]);
        return array_map(fn(array $r):array=>['name'=>(string)($r['title']?:\Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.dokument')),'url'=>$this->mediaUrl($r['storage_key']),'type'=>(string)($r['document_type']??'document')],$rows);
    }

    /** @return array{value:float,count:int} */
    private function rating(int $productId):array
    {
        $row=$this->connection->fetchAssociative("SELECT COUNT(*) cnt,COALESCE(AVG(rating),0) avg_rating FROM mc_product_review WHERE product_id=? AND status='published'",[$productId]);
        return ['value'=>round((float)($row['avg_rating']??0),1),'count'=>(int)($row['cnt']??0)];
    }

    /** @return list<array{author:string,rating:int,text:string}> */
    private function reviews(int $productId,StorefrontContext $context):array
    {
        $rows=$this->connection->fetchAllAssociative("SELECT r.id,r.author_name,r.rating,r.title,r.body,r.verified_purchase,r.helpful_count,r.merchant_reply,r.merchant_replied_at FROM mc_product_review r WHERE r.product_id=? AND r.store_id=? AND r.status='published' ORDER BY r.published_at DESC,r.id DESC LIMIT 20",[$productId,$context->storeId]);
        foreach($rows as &$row){
            $media=$this->connection->fetchAllAssociative("SELECT ma.storage_key FROM mc_review_media rm JOIN mc_media_asset ma ON ma.id=rm.media_id WHERE rm.review_id=? ORDER BY rm.sort_order,rm.media_id LIMIT 4",[(int)$row['id']]);
            $row['images']=array_map(fn(array $m):string=>$this->mediaUrl($m['storage_key']??null),$media);
        }unset($row);
        return array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],'author'=>(string)$r['author_name'],'rating'=>(int)$r['rating'],'title'=>(string)($r['title']??''),'text'=>(string)$r['body'],
            'verified'=>(bool)$r['verified_purchase'],'helpful'=>(int)$r['helpful_count'],'reply'=>(string)($r['merchant_reply']??''),'images'=>(array)($r['images']??[]),
        ],$rows);
    }

    /** @return list<array{author:string,question:string,answer:string}> */
    private function questions(int $productId, StorefrontContext $context): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT author_name,question,answer FROM mc_product_question WHERE product_id=? AND store_id=? AND status='published' AND answer IS NOT NULL AND answer<>'' ORDER BY published_at DESC,id DESC LIMIT 20",
                [$productId, $context->storeId],
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn(array $r): array => [
            'author' => (string) $r['author_name'],
            'question' => (string) $r['question'],
            'answer' => (string) $r['answer'],
        ], $rows);
    }

    /** @return list<array{name:string,url?:string}> */
    private function breadcrumbs(int $productId,StorefrontContext $context,string $productName):array
    {
        $items=[['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.holovna'),'url'=>'/'],['name'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.kataloh'),'url'=>'/catalog']];
        $categoryId=$this->connection->fetchOne('SELECT category_id FROM mc_product_category WHERE product_id=? ORDER BY is_primary DESC,sort_order ASC,category_id ASC LIMIT 1',[$productId]);
        $chain=[]; $guard=0;
        while($categoryId!==false && $categoryId!==null && $guard++<32){
            $r=$this->connection->fetchAssociative("SELECT c.parent_id,ct.name,sr.path FROM mc_category c JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=? JOIN mc_seo_route sr ON sr.store_id=? AND sr.locale=? AND sr.entity_type='category' AND sr.entity_public_id=c.public_id WHERE c.id=? LIMIT 1",[$context->storeId,$context->locale,$context->storeId,$context->locale,(int)$categoryId]);
            if(!is_array($r)){break;} $chain[]=['name'=>(string)$r['name'],'url'=>'/'.ltrim((string)$r['path'],'/')]; $categoryId=$r['parent_id'];
        }
        foreach(array_reverse($chain) as $c){$items[]=$c;} $items[]=['name'=>$productName]; return $items;
    }

    private function mediaUrl(mixed $key):string
    {
        if(!is_string($key)||trim($key)===''){return '/assets/product-placeholder.svg';}
        $key=str_replace('\\','/',trim($key)); if(str_contains($key,'..')){return '/assets/product-placeholder.svg';}
        return '/media/'.ltrim($key,'/');
    }
    private function trimDecimal(string $value):string { return rtrim(rtrim($value,'0'),'.') ?: '0'; }
}
