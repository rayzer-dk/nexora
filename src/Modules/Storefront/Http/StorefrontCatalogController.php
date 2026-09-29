<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Http;

use Commerce\Core\Extension\ExtensionRouteController;
use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\ProductPage\Application\ProductPageComposer;
use Commerce\Modules\Content\Infrastructure\DbalBlogQuery;
use Commerce\Modules\Seo\StructuredData\ArticleStructuredDataBuilder;
use Commerce\Modules\ProductPage\Application\ProductPageLayoutLoader;
use Commerce\Modules\Seo\Application\SeoRouteResolver;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Commerce\Modules\Seo\StructuredData\BreadcrumbListBuilder;
use Commerce\Modules\Seo\StructuredData\ProductMerchantListingBuilder;
use Commerce\Modules\Seo\StructuredData\StructuredDataGraphBuilder;
use Commerce\Modules\Storefront\Domain\ProductCatalogFilter;
use Commerce\Modules\Analytics\Application\SearchAnalyticsRecorder;
use Commerce\Modules\Storefront\Infrastructure\CachedStorefrontCatalogQuery;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use JsonException;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StorefrontCatalogController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly CachedStorefrontCatalogQuery $catalog,
        private readonly SeoRouteResolver $seo,
        private readonly ProductPageLayoutLoader $layoutLoader,
        private readonly ProductPageComposer $composer,
        private readonly ProductMerchantListingBuilder $productSchema,
        private readonly BreadcrumbListBuilder $breadcrumbsSchema,
        private readonly StructuredDataGraphBuilder $graph,
        private readonly DbalBlogQuery $blog,
        private readonly ArticleStructuredDataBuilder $articleSchema,
        private readonly Connection $db,
        private readonly SearchAnalyticsRecorder $searchAnalytics,
        private readonly ExtensionRouteController $extensionRoutes,
        private readonly SiteCapabilitySettings $capabilities,
    ) {
    }

    #[Route('/api/storefront/search/suggest', name: 'storefront_search_suggest', methods: ['GET'], priority: 320)]
    public function searchSuggest(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query, 'UTF-8') < 2) {
            return $this->json(['items' => []], 200, ['Cache-Control' => 'no-store']);
        }
        $query = mb_substr($query, 0, 80, 'UTF-8');
        try {
            $context = $this->contexts->resolve($request);
            $result = $this->catalog->products($context, null, 1, 6, $query);
            $items = array_map(static fn (array $item): array => [
                'name' => (string) ($item['name'] ?? ''),
                'url' => (string) ($item['url'] ?? '/catalog'),
                'price' => (string) ($item['price'] ?? ''),
                'image' => (string) ($item['image'] ?? '/assets/product-placeholder.svg'),
                'availability' => (string) ($item['availability_label'] ?? ''),
            ], (array) ($result['items'] ?? []));
            return $this->json(['items' => $items], 200, ['Cache-Control' => 'private, max-age=30']);
        } catch (\Throwable) {
            // Search suggestions are optional progressive enhancement. The regular catalog search remains usable.
            return $this->json(['items' => []], 200, ['Cache-Control' => 'no-store']);
        }
    }

    #[Route('/api/storefront/catalog/cursor', name: 'storefront_catalog_cursor', methods: ['GET'], priority: 315)]
    public function catalogCursor(Request $request): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        $categoryId = null;
        $categoryPublicId = trim((string)$request->query->get('category', ''));
        if ($categoryPublicId !== '') {
            $category = $this->catalog->categoryByPublicId($context, $categoryPublicId);
            if ($category === null) {
                return $this->json(['items'=>[], 'next_cursor'=>null], 404, ['Cache-Control'=>'no-store']);
            }
            $categoryId = (int)$category['id'];
        }
        $after = $request->query->getInt('after', 0);
        $limit = min(60, max(1, $request->query->getInt('limit', 24)));
        $result = $this->catalog->productsByCursor($context, $categoryId, $after > 0 ? $after : null, $limit);
        return $this->json($result, 200, ['Cache-Control'=>'private, max-age=5']);
    }

    #[Route('/catalog', name: 'storefront_catalog', methods: ['GET'], priority: 100)]
    public function catalog(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $page = max(1, $request->query->getInt('page', 1));
        $filter = $this->catalogFilter($request);
        if ($page === 1 && $filter->search !== '' && !$filter->isFilteredExceptSearch()) {
            $exactUrl = $this->exactIdentifierUrl($context->storeId, $context->locale, $filter->search);
            if ($exactUrl !== null) {
                return $this->redirect($exactUrl, 302);
            }
        }
        $products = $this->catalog->products($context, null, $page, 24, null, $filter);
        if ($page > (int) ($products['pages'] ?? 1)) {
            // Pages past the end are soft-404s ("empty but indexable"); answer 404 instead.
            throw $this->createNotFoundException();
        }
        if ($page === 1 && $filter->search !== '') $this->searchAnalytics->record($context->storeId, $context->locale, $filter->search, (int)($products['total'] ?? 0));
        $facets = $this->catalog->catalogFacets($context);
        $query = $this->filterQuery($request);
        $canonical = $request->getSchemeAndHttpHost() . '/catalog' . (!$filter->isFiltered() && $page > 1 ? '?page=' . $page : '');

        return $this->render('@storefront/catalog/index.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.kataloh'),
            'store_name' => $context->storeName,
            'categories' => $this->catalog->topCategories($context),
            'products' => $products,
            'search_query' => $filter->search,
            'catalog_filter' => $filter,
            'catalog_facets' => $facets,
            'catalog_query' => $query,
            'catalog_query_base' => $this->filterQueryString($request),
            'seo_head' => [
                'description' => \Commerce\Core\I18n\CanonicalUiText::get('seo.catalog.description', ['store' => $context->storeName]),
                'canonical' => $canonical,
                'robots' => $filter->isFiltered() ? 'noindex,follow' : 'index,follow,max-image-preview:large',
            ],
        ]);
    }

    #[Route('/{path}', name: 'storefront_seo_entity', methods: ['GET'], requirements: ['path' => '.+'], priority: -1000)]
    public function entity(Request $request, string $path): Response
    {
        $extensionResponse = $this->extensionRoutes->dispatch($request, $path);
        if ($extensionResponse instanceof Response) {
            return $extensionResponse;
        }
        $context = $this->contexts->resolve($request);
        $resolved = $this->seo->resolve($context->storeId, $context->locale, $path);
        if ($resolved->route === null) {
            // The visitor switched to a language this page is not translated into yet. Show the
            // default-language content (the URL is the default-language URL anyway) instead of a 404;
            // the interface stays in the chosen language.
            $defaultLocale = $this->contexts->defaultLocale($context->storeId);
            if ($defaultLocale !== null && $defaultLocale !== $context->locale) {
                $fallback = $this->seo->resolve($context->storeId, $defaultLocale, $path);
                if ($fallback->route !== null) {
                    $resolved = $fallback;
                    $context = new \Commerce\Modules\Storefront\Domain\StorefrontContext($context->storeId, $context->marketId, $defaultLocale, $context->currency, $context->countryCode, $context->storeName);
                    $request->attributes->set('_content_locale_fallback', $defaultLocale);
                }
            }
        }
        if ($resolved->route === null) {
            throw $this->createNotFoundException();
        }
        if ($resolved->isRedirect()) {
            return $this->redirect('/' . ltrim($resolved->route->path, '/'), $resolved->redirectStatus ?? 301);
        }

        $requiredFeature = match ($resolved->route->entityType) {
            SeoEntityType::Product, SeoEntityType::Category => 'catalog',
            SeoEntityType::BlogArticle => 'blog',
            default => null,
        };
        if ($requiredFeature !== null && !$this->capabilities->enabled($context->storeId, $requiredFeature)) {
            throw $this->createNotFoundException();
        }

        return match ($resolved->route->entityType) {
            SeoEntityType::Product => $this->product($request, $context, $resolved->route->entityPublicId),
            SeoEntityType::Category => $this->category($request, $context, $resolved->route->entityPublicId),
            SeoEntityType::BlogArticle => $this->blogArticle($request, $context, $resolved->route->entityPublicId),
            default => throw $this->createNotFoundException(),
        };
    }

    private function category(Request $request, $context, string $publicId): Response
    {
        $category = $this->catalog->categoryByPublicId($context, $publicId);
        if ($category === null) {
            throw $this->createNotFoundException();
        }
        $page = max(1, $request->query->getInt('page', 1));
        $filter = $this->catalogFilter($request);
        $products = $this->catalog->products($context, (int) $category['id'], $page, 24, null, $filter);
        if ($page > (int) ($products['pages'] ?? 1)) {
            throw $this->createNotFoundException();
        }
        if ($page === 1 && $filter->search !== '') $this->searchAnalytics->record($context->storeId, $context->locale, $filter->search, (int)($products['total'] ?? 0));
        $facets = $this->catalog->catalogFacets($context, (int) $category['id']);
        $canonical = $request->getSchemeAndHttpHost() . $category['url'] . (!$filter->isFiltered() && $page > 1 ? '?page=' . $page : '');

        return $this->render('@storefront/category/show.html.twig', [
            'page_title' => $category['name'],
            'store_name' => $context->storeName,
            'category' => $category,
            'products' => $products,
            'search_query' => $filter->search,
            'catalog_filter' => $filter,
            'catalog_facets' => $facets,
            'catalog_query' => $this->filterQuery($request),
            'catalog_query_base' => $this->filterQueryString($request),
            'seo_head' => [
                'title' => (string) ($category['meta_title'] ?? '') !== '' ? (string) $category['meta_title'] : (string) ($category['name'] ?? ''),
                'description' => (string) ($category['meta_description'] ?? '') !== '' ? (string) $category['meta_description'] : (string) ($category['description'] ?? ''),
                'image' => $this->shareImage($request->getSchemeAndHttpHost(), [(string) ($category['image'] ?? '')]),
                'canonical' => $canonical,
                'robots' => $filter->isFiltered() ? 'noindex,follow' : 'index,follow,max-image-preview:large',
                'hreflang' => $filter->isFiltered() ? [] : [$context->locale => $canonical, 'x-default' => $canonical],
            ],
        ]);
    }

    private function blogArticle(Request $request, $context, string $publicId): Response
    {
        $article = $this->blog->byPublicId($context->storeId, $context->locale, $publicId);
        if ($article === null) {
            throw $this->createNotFoundException();
        }
        $baseUrl = $request->getSchemeAndHttpHost();
        $ownUrl = $baseUrl . $article['url'];
        $canonical = (string) $article['canonical_url'] !== '' ? (string) $article['canonical_url'] : $ownUrl;
        $blogLabel = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.content.http.blogcontroller.bloh');
        $breadcrumbs = [['name' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.infrastructure.dbalstorefrontcatalogquery.holovna'), 'url' => $baseUrl . '/'], ['name' => $blogLabel, 'url' => $baseUrl . '/blog']];
        if (is_array($article['category'])) {
            $breadcrumbs[] = ['name' => (string) $article['category']['name'], 'url' => $baseUrl . '/blog/category/' . $article['category']['slug']];
        }
        $breadcrumbs[] = ['name' => $article['title']];
        $image = (string) $article['image'] !== '' ? (str_starts_with((string) $article['image'], 'http') ? (string) $article['image'] : $baseUrl . $article['image']) : '';
        $schema = [
            'title' => $article['title'],
            'url' => $ownUrl,
            'published_at' => $article['published_iso'],
            'modified_at' => $article['updated_iso'],
            'author' => (string) $article['author'] !== '' ? $article['author'] : $context->storeName,
            'description' => $article['meta_description'],
            'image' => $image !== '' ? [$image] : [],
        ];
        $articleNode = $this->articleSchema->build($schema);
        $articleNode['mainEntityOfPage'] = ['@type' => 'WebPage', '@id' => $ownUrl];
        $articleNode['inLanguage'] = $context->locale;
        $articleNode['wordCount'] = (int) preg_match_all('/[\p{L}\p{N}]+/u', \Commerce\Modules\Content\Application\BlogContentProcessor::plainText((string) $article['body_html']));
        if (is_array($article['category'])) {
            $articleNode['articleSection'] = (string) $article['category']['name'];
        }
        if ($article['tags'] !== []) {
            $articleNode['keywords'] = implode(', ', array_map(static fn (array $t): string => (string) $t['name'], $article['tags']));
        }
        try {
            $structuredDataJson = json_encode(
                $this->graph->build($articleNode, $this->breadcrumbsSchema->build($breadcrumbs)),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $structuredDataJson = '{}';
        }
        $hreflang = [];
        if (!$article['noindex']) {
            foreach ($this->blog->alternates($context->storeId, $publicId) as $locale => $path) {
                $hreflang[$locale] = $baseUrl . $path;
            }
            $hreflang = $hreflang === [] ? [$context->locale => $ownUrl] : $hreflang;
            $hreflang['x-default'] = $hreflang[$context->locale] ?? $ownUrl;
        }
        return $this->render('@storefront/blog/article.html.twig', [
            'page_title' => (string) ($article['meta_title'] ?? '') !== '' ? (string) $article['meta_title'] : (string) ($article['title'] ?? ''),
            'store_name' => $context->storeName,
            'article' => $article,
            'breadcrumbs' => $breadcrumbs,
            'related' => $this->blog->related($context->storeId, $context->locale, (int) $article['id'], (int) $article['category_id'], 3),
            'neighbors' => $this->blog->neighbors($context->storeId, $context->locale, (int) $article['id'], (string) $article['published_at']),
            'share_url' => $ownUrl,
            'structured_data_json' => $structuredDataJson,
            'seo_head' => [
                'title' => (string) $article['meta_title'],
                'description' => (string) $article['meta_description'],
                'image' => $image,
                'type' => 'article',
                'canonical' => $canonical,
                'robots' => $article['noindex'] ? 'noindex,follow' : 'index,follow,max-image-preview:large',
                'hreflang' => $hreflang,
            ],
        ]);
    }

    private function product(Request $request, $context, string $publicId): Response
    {
        $product = $this->catalog->productByPublicId($context, $publicId, (string) $request->query->get('variant', ''));
        if ($product === null) {
            throw $this->createNotFoundException();
        }
        $reviewsEnabled = $this->capabilities->enabled($context->storeId, 'reviews');
        if (!$reviewsEnabled) {
            $product['rating'] = null;
            $product['reviews'] = [];
            $product['questions'] = [];
        }

        $baseUrl = $request->getSchemeAndHttpHost();
        $product['url'] = $baseUrl . $product['url'];
        $displayImage = (string) ($product['image'] ?? '');
        if ($displayImage !== '' && str_starts_with($displayImage, '/')) {
            $product['image'] = $baseUrl . $displayImage;
        }
        foreach ($product['images'] as &$image) {
            $image['url'] = $baseUrl . $image['url'];
        }
        unset($image);
        $breadcrumbs = $product['breadcrumbs'];
        foreach ($breadcrumbs as &$item) {
            if (isset($item['url'])) {
                $item['url'] = $baseUrl . $item['url'];
            }
        }
        unset($item);
        $schemaProduct = $this->productSchema->build($product);
        $schemaBreadcrumb = $this->breadcrumbsSchema->build($breadcrumbs);
        try {
            $structuredDataJson = json_encode($this->graph->build($schemaProduct, $schemaBreadcrumb), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $structuredDataJson = '{}';
        }
        foreach ($product['images'] as &$image) {
            $image['url'] = str_starts_with($image['url'], $baseUrl) ? substr($image['url'], strlen($baseUrl)) : $image['url'];
        }
        unset($image);
        $product['url'] = parse_url($product['url'], PHP_URL_PATH) ?: '/';
        $product['image'] = $displayImage;
        $product['breadcrumbs'] = array_map(static function (array $item) use ($baseUrl): array {
            if (isset($item['url']) && str_starts_with($item['url'], $baseUrl)) {
                $item['url'] = substr($item['url'], strlen($baseUrl));
            }
            return $item;
        }, $breadcrumbs);

        $layout = $this->layoutLoader->loadForStore($context->storeId);
        $regions = $this->composer->compose($layout);
        if (!$reviewsEnabled) {
            foreach ($regions as &$blocks) {
                $blocks = array_values(array_filter(
                    $blocks,
                    static fn ($block): bool => !in_array($block->type, ['rating_summary', 'reviews', 'qa'], true),
                ));
            }
            unset($blocks);
        }

        return $this->render('@storefront/product/show.html.twig', [
            'page_title' => $product['name'],
            'store_name' => $context->storeName,
            'product' => $product,
            'layout' => $layout,
            'regions' => $regions,
            'structured_data_json' => $structuredDataJson,
            'seo_head' => [
                'title' => (string) ($product['meta_title'] ?? '') !== '' ? (string) $product['meta_title'] : (string) ($product['name'] ?? ''),
                'description' => (string) ($product['meta_description'] ?? '') !== '' ? (string) $product['meta_description'] : ((string) ($product['short_description'] ?? '') !== '' ? (string) $product['short_description'] : (string) ($product['description'] ?? '')),
                'image' => $this->shareImage($baseUrl, [$displayImage, ...array_map(static fn (array $image): string => (string) ($image['url'] ?? ''), $product['images'])]),
                'type' => 'product',
                'canonical' => $baseUrl . $product['url'],
                'robots' => 'index,follow,max-image-preview:large',
                'hreflang' => [$context->locale => $baseUrl . $product['url'], 'x-default' => $baseUrl . $product['url']],
            ],
        ]);
    }
    /**
     * First real raster image for og:image; placeholders are never shared.
     *
     * @param list<string> $candidates
     */
    private function shareImage(string $baseUrl, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || str_contains($candidate, 'placeholder')) {
                continue;
            }
            return str_starts_with($candidate, '/') ? $baseUrl . $candidate : $candidate;
        }
        return '';
    }

    private function catalogFilter(Request $request): ProductCatalogFilter
    {
        $search = mb_substr(trim((string) $request->query->get('q', '')), 0, 120, 'UTF-8');
        $brand = $request->query->getInt('brand', 0);
        $sort = (string) $request->query->get('sort', ProductCatalogFilter::SORT_NEWEST);
        if (!in_array($sort, ProductCatalogFilter::SORTS, true)) {
            $sort = ProductCatalogFilter::SORT_NEWEST;
        }
        $min = $this->priceMinor($request->query->get('min_price'));
        $max = $this->priceMinor($request->query->get('max_price'));
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }
        return new ProductCatalogFilter(
            search: $search,
            brandId: $brand > 0 ? $brand : null,
            inStockOnly: $request->query->getBoolean('in_stock', false),
            minPriceMinor: $min,
            maxPriceMinor: $max,
            sort: $sort,
            attributeFilters: $this->attributeFilters($request),
        );
    }

    private function priceMinor(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }
        $raw = trim(str_replace(',', '.', (string) $value));
        if ($raw === '' || preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $raw) !== 1) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $minor = ((int) $whole * 100) + (int) $fraction;
        return min($minor, 999_999_999_99);
    }

    /** @return array<string,mixed> */
    private function filterQuery(Request $request): array
    {
        $allowed = ['q', 'brand', 'in_stock', 'min_price', 'max_price', 'sort'];
        $result = [];
        foreach ($allowed as $key) {
            $value = $request->query->get($key);
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '' && $text !== '0') {
                $result[$key] = mb_substr($text, 0, 120, 'UTF-8');
            }
        }
        $attributes = $this->attributeFilters($request);
        if ($attributes !== []) {
            $result['attr'] = $attributes;
        }
        return $result;
    }

    private function filterQueryString(Request $request): string
    {
        return http_build_query($this->filterQuery($request), '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,list<string>> */
    private function attributeFilters(Request $request): array
    {
        try {
            $raw = $request->query->all('attr');
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach (array_slice($raw, 0, 12, true) as $code => $values) {
            if (!is_string($code) || preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $code) !== 1) {
                continue;
            }
            $values = is_array($values) ? $values : [$values];
            $normalized = [];
            foreach (array_slice($values, 0, 12) as $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $token = trim((string) $value);
                if (preg_match('/^b:[01]$/D', $token) === 1
                    || preg_match('/^d:-?\d{1,19}(?:\.\d{1,10})?$/D', $token) === 1
                    || preg_match('/^t:[A-Fa-f0-9]{64}$/D', $token) === 1) {
                    $normalized[] = $token;
                }
            }
            $normalized = array_values(array_unique($normalized));
            if ($normalized !== []) {
                $result[$code] = $normalized;
            }
        }
        return $result;
    }

    private function exactIdentifierUrl(int $storeId,string $locale,string $query): ?string
    {
        $value=trim($query); if($value===''||mb_strlen($value,'UTF-8')>190)return null;
        try{
            $rows=$this->db->fetchAllAssociative("SELECT DISTINCT sr.path,v.public_id variant_public_id FROM mc_product_variant v JOIN mc_product p ON p.id=v.product_id AND p.status='published' JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? AND sp.status='active' JOIN mc_seo_route sr ON sr.id=(SELECT srx.id FROM mc_seo_route srx JOIN mc_store srxs ON srxs.id=srx.store_id WHERE srx.store_id=? AND srx.locale IN (?,srxs.default_locale) AND srx.entity_type='product' AND srx.entity_public_id=p.public_id ORDER BY (srx.locale=srxs.default_locale) ASC LIMIT 1) WHERE v.status='active' AND (v.sku=? OR v.gtin=? OR v.mpn=?) LIMIT 2",[$storeId,$storeId,$locale,$value,$value,$value]);
            if(count($rows)!==1)return null;
            $variant=\Symfony\Component\Uid\Uuid::fromBinary((string)$rows[0]['variant_public_id'])->toRfc4122();
            return '/'.ltrim((string)$rows[0]['path'],'/').'?variant='.rawurlencode($variant);
        }catch(\Throwable){return null;}
    }

}
