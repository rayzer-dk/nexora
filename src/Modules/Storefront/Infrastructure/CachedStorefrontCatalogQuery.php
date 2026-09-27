<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Commerce\Modules\Storefront\Domain\ProductCatalogFilter;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Storefront\Projection\StorefrontFacetProjectionStore;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class CachedStorefrontCatalogQuery
{
    public function __construct(
        private DbalStorefrontCatalogQuery $inner,
        private CacheInterface $cache,
        private StorefrontFacetProjectionStore $facetProjection,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function topCategories(StorefrontContext $context, int $limit = 24): array
    {
        $limit = min(60, max(1, $limit));
        return $this->cache->get($this->key('top-categories', $context, [$limit]), function (ItemInterface $item) use ($context, $limit): array {
            $item->expiresAfter(300);
            return $this->inner->topCategories($context, $limit);
        });
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
        $filter ??= new ProductCatalogFilter(search: trim((string) $search));
        $payload = [
            'category' => $categoryId,
            'page' => $page,
            'limit' => $limit,
            'search' => $filter->search,
            'brand' => $filter->brandId,
            'stock' => $filter->inStockOnly,
            'min' => $filter->minPriceMinor,
            'max' => $filter->maxPriceMinor,
            'sort' => $filter->sort,
            'attrs' => $filter->attributeFilters,
        ];

        return $this->cache->get($this->key('products', $context, $payload), function (ItemInterface $item) use ($context, $categoryId, $page, $limit, $filter): array {
            // Short TTL deliberately bounds display staleness. Cart and checkout always revalidate live stock and price.
            $item->expiresAfter(8);
            return $this->inner->products($context, $categoryId, $page, $limit, null, $filter);
        });
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?int} */
    public function productsByCursor(StorefrontContext $context, ?int $categoryId = null, ?int $afterProductId = null, int $limit = 24): array
    {
        return $this->inner->productsByCursor($context, $categoryId, $afterProductId, $limit);
    }

    /** @return array{brands:list<array{id:int,name:string,count:int}>,price_min_minor:?int,price_max_minor:?int,attributes:list<array{code:string,name:string,data_type:string,unit_label:?string,values:list<array{token:string,label:string,count:int}>}>} */
    public function catalogFacets(StorefrontContext $context, ?int $categoryId = null): array
    {
        $precomputed = $this->facetProjection->get($context, $categoryId);
        if (is_array($precomputed)) {
            return $precomputed;
        }
        return $this->cache->get($this->key('facets', $context, [$categoryId]), function (ItemInterface $item) use ($context, $categoryId): array {
            $item->expiresAfter(60);
            return $this->inner->catalogFacets($context, $categoryId);
        });
    }

    /** @return array<string,mixed>|null */
    public function categoryByPublicId(StorefrontContext $context, string $publicId): ?array
    {
        return $this->cache->get($this->key('category', $context, [$publicId]), function (ItemInterface $item) use ($context, $publicId): ?array {
            $item->expiresAfter(300);
            return $this->inner->categoryByPublicId($context, $publicId);
        });
    }

    /** @return array<string,mixed>|null */
    public function productByPublicId(StorefrontContext $context, string $publicId, ?string $selectedVariantPublicId = null): ?array
    {
        return $this->cache->get($this->key('product', $context, [$publicId, $selectedVariantPublicId]), function (ItemInterface $item) use ($context, $publicId, $selectedVariantPublicId): ?array {
            $item->expiresAfter(8);
            return $this->inner->productByPublicId($context, $publicId, $selectedVariantPublicId);
        });
    }

    /** @param array<mixed> $parts */
    private function key(string $scope, StorefrontContext $context, array $parts): string
    {
        $payload = [
            'scope' => $scope,
            'store' => $context->storeId,
            'market' => $context->marketId,
            'locale' => $context->locale,
            'currency' => $context->currency,
            'country' => $context->countryCode,
            'parts' => $parts,
        ];
        return 'storefront.' . $scope . '.' . hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
