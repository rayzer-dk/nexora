<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Http;

use Commerce\Core\Extension\ExtensionRouteController;
use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Storefront\Application\CategoryLayoutService;
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
        private readonly \Commerce\Modules\Seo\Application\SeoTemplateService $seoTemplates,
        private readonly ProductPageLayoutLoader $layoutLoader,
        private readonly CategoryLayoutService $categoryLayout,
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
        private readonly \Commerce\Modules\Content\Infrastructure\DbalInformationPageQuery $informationPages,
        private readonly \Commerce\Modules\Seo\Application\SeoSettings $seoSettings,
        private readonly \Commerce\Modules\Storefront\Infrastructure\CategoryProductPath $categoryPaths,
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
        $perPage = $this->perPage($request);
        $products = $this->catalog->products($context, null, $page, $perPage, null, $filter);
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
            'catalog_per_page' => $perPage,
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
        if ($resolved->route === null && str_contains($path, '/') && $this->seoSettings->categoryPathInProductUrl($context->storeId)) {
            // /category/subcategory/product: an alias of the flat product address (which stays canonical).
            $nested = $this->categoryPaths->productRoute($context->storeId, $context->locale, $path);
            if ($nested !== null) {
                $resolved = \Commerce\Modules\Seo\Domain\SeoRouteResolution::canonical($nested);
            }
        }
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
            SeoEntityType::CmsPage => 'content',
            default => null,
        };
        if ($requiredFeature !== null && !$this->capabilities->enabled($context->storeId, $requiredFeature)) {
            throw $this->createNotFoundException();
        }

        $request->attributes->set('_analytics_page', strtolower($resolved->route->entityType->name));

        return match ($resolved->route->entityType) {
            SeoEntityType::Product => $this->product($request, $context, $resolved->route->entityPublicId),
            SeoEntityType::Category => $this->category($request, $context, $resolved->route->entityPublicId),
            SeoEntityType::BlogArticle => $this->blogArticle($request, $context, $resolved->route->entityPublicId),
            SeoEntityType::CmsPage => $this->cmsPage($request, $context, $resolved->route->entityPublicId, $resolved->route->path),
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
        $perPage = $this->perPage($request);
        $products = $this->catalog->products($context, (int) $category['id'], $page, $perPage, null, $filter);
        $categoryTrail = $this->catalog->categoryTrail($context, (int) $category['id']);
        if ($this->seoSettings->categoryPathInProductUrl($context->storeId)) {
            // With the category path switched on, the product links of a category page carry the path of this category.
            $prefix = implode('/', array_map(static fn (array $step): string => basename((string) parse_url((string) ($step['url'] ?? ''), PHP_URL_PATH)), [...$categoryTrail, ['url' => (string) ($category['url'] ?? '')]]));
            foreach ($products['items'] as &$item) {
                if (isset($item['url']) && $prefix !== '' && !str_contains(ltrim((string) $item['url'], '/'), '/')) {
                    $item['url'] = '/' . $prefix . $item['url'];
                }
            }
            unset($item);
        }
        if ($page > (int) ($products['pages'] ?? 1)) {
            throw $this->createNotFoundException();
        }
        if ($page === 1 && $filter->search !== '') $this->searchAnalytics->record($context->storeId, $context->locale, $filter->search, (int)($products['total'] ?? 0));
        $recommended = [];
        if ($page === 1 && !$filter->isFiltered() && (int) ($products['total'] ?? 0) > 8) {
            // A short "recommended in this category" strip: best sellers of the last 180 days that are in stock.
            $recommended = (array) ($this->catalog->products($context, (int) $category['id'], 1, 4, null, new ProductCatalogFilter(inStockOnly: true, sort: ProductCatalogFilter::SORT_POPULAR))['items'] ?? []);
        }
        $facets = $this->catalog->catalogFacets($context, (int) $category['id']);
        $layout = $this->categoryLayout->active($context->storeId);
        $subcategories = [];
        if ($page === 1 && !$filter->isFiltered() && ($layout['show']['subcategories'] ?? true)) {
            $subcategories = $this->catalog->childCategories($context, (int) $category['id'], $layout['subcategories']['limit'], $layout['subcategories']['order']);
        }
        $canonical = $request->getSchemeAndHttpHost() . $category['url'] . (!$filter->isFiltered() && $page > 1 ? '?page=' . $page : '');

        return $this->render('@storefront/category/show.html.twig', [
            'page_title' => $category['name'],
            'store_name' => $context->storeName,
            'category' => $category,
            'products' => $products,
            'recommended_products' => $recommended,
            'category_layout' => $layout,
            'subcategories' => $subcategories,
            'category_trail' => $categoryTrail,
            'category_description_position' => $this->seoSettings->categoryDescriptionPosition($context->storeId),
            'search_query' => $filter->search,
            'catalog_filter' => $filter,
            'catalog_facets' => $facets,
            'catalog_query' => $this->filterQuery($request),
            'catalog_per_page' => $perPage,
            'catalog_query_base' => $this->filterQueryString($request),
            'seo_head' => [
                'title' => (string) ($category['meta_title'] ?? '') !== '' ? $this->seoTemplates->expand((string) $category['meta_title'], ['name' => (string) ($category['name'] ?? ''), 'store' => $context->storeName]) : $this->seoTemplates->render($context->storeId, $context->locale, 'category', 'title', ['name' => (string) ($category['name'] ?? ''), 'store' => $context->storeName], (string) ($category['name'] ?? '')),
                'description' => (string) ($category['meta_description'] ?? '') !== '' ? $this->seoTemplates->expand((string) $category['meta_description'], ['name' => (string) ($category['name'] ?? ''), 'store' => $context->storeName]) : $this->seoTemplates->render($context->storeId, $context->locale, 'category', 'description', ['name' => (string) ($category['name'] ?? ''), 'store' => $context->storeName], (string) ($category['description'] ?? '')),
                'image' => $this->shareImage($request->getSchemeAndHttpHost(), [(string) ($category['image'] ?? '')]),
                'canonical' => $canonical,
                'robots' => $filter->isFiltered() ? 'noindex,follow' : 'index,follow,max-image-preview:large',
                'hreflang' => $filter->isFiltered() ? [] : [$context->locale => $canonical, 'x-default' => $canonical],
            ],
        ]);
    }

    /** A page the merchant added under Content > Information pages, served at its own /{slug}. Drafts are not public. */
    private function cmsPage(Request $request, $context, string $publicId, string $path): Response
    {
        $page = $this->informationPages->byPublicId($context, $publicId);
        if ($page === null || $page['status'] !== 'published' || trim((string) ($page['body_html'] ?? '')) === '') {
            throw $this->createNotFoundException();
        }
        $base = $request->getSchemeAndHttpHost();
        $own = $base . '/' . $path;
        $canonical = trim((string) ($page['canonical_url'] ?? '')) !== '' ? (string) $page['canonical_url'] : $own;
        $image = (string) ($page['og_key'] ?? '') !== '' ? $this->shareImage($base, ['/media/' . ltrim((string) $page['og_key'], '/')]) : null;

        return $this->render('@storefront/content/page.html.twig', [
            'page_title' => (string) $page['title'],
            'store_name' => $context->storeName,
            'page' => $page,
            'published' => true,
            'seo_head' => [
                'title' => (string) ($page['meta_title'] ?? '') !== '' ? $this->seoTemplates->expand((string) $page['meta_title'], ['name' => (string) ($page['title'] ?? ''), 'store' => $context->storeName]) : $this->seoTemplates->render($context->storeId, $context->locale, 'page', 'title', ['name' => (string) ($page['title'] ?? ''), 'store' => $context->storeName], ''),
                'description' => (string) ($page['meta_description'] ?? '') !== '' ? $this->seoTemplates->expand((string) $page['meta_description'], ['name' => (string) ($page['title'] ?? ''), 'store' => $context->storeName]) : $this->seoTemplates->render($context->storeId, $context->locale, 'page', 'description', ['name' => (string) ($page['title'] ?? ''), 'store' => $context->storeName], (string) ($page['excerpt'] ?? '')),
                'image' => $image,
                'canonical' => $canonical,
                'robots' => !empty($page['noindex']) ? 'noindex,follow' : 'index,follow,max-image-preview:large',
                'hreflang' => [$context->locale => $own, 'x-default' => $own],
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
            foreach ($this->blog->categoryTrail($context->storeId, $context->locale, (string) $article['category']['slug']) as $step) {
                $breadcrumbs[] = ['name' => $step['name'], 'url' => $baseUrl . '/blog/category/' . $step['slug']];
            }
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
                'title' => (string) $article['meta_title'] !== '' ? $this->seoTemplates->expand((string) $article['meta_title'], ['name' => (string) ($article['title'] ?? ''), 'store' => $context->storeName]) : $this->seoTemplates->render($context->storeId, $context->locale, 'article', 'title', ['name' => (string) ($article['title'] ?? ''), 'store' => $context->storeName], ''),
                'description' => (string) $article['meta_description'] !== '' ? $this->seoTemplates->expand((string) $article['meta_description'], ['name' => (string) ($article['title'] ?? ''), 'store' => $context->storeName]) : $this->seoTemplates->render($context->storeId, $context->locale, 'article', 'description', ['name' => (string) ($article['title'] ?? ''), 'store' => $context->storeName], ''),
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
                'title' => (string) ($product['meta_title'] ?? '') !== '' ? $this->seoTemplates->expand((string) $product['meta_title'], ['name' => (string) ($product['name'] ?? ''), 'store' => $context->storeName, 'price' => (string) ($product['price'] ?? ''), 'brand' => (string) ($product['brand'] ?? ''), 'sku' => (string) ($product['sku'] ?? ''), 'category' => (string) (($product['breadcrumbs'][count($product['breadcrumbs'] ?? []) - 2]['name'] ?? ''))]) : $this->seoTemplates->render($context->storeId, $context->locale, 'product', 'title', ['name' => (string) ($product['name'] ?? ''), 'store' => $context->storeName, 'price' => (string) ($product['price'] ?? ''), 'brand' => (string) ($product['brand'] ?? ''), 'sku' => (string) ($product['sku'] ?? ''), 'category' => (string) (($product['breadcrumbs'][count($product['breadcrumbs'] ?? []) - 2]['name'] ?? ''))], (string) ($product['name'] ?? '')),
                'description' => (string) ($product['meta_description'] ?? '') !== '' ? $this->seoTemplates->expand((string) $product['meta_description'], ['name' => (string) ($product['name'] ?? ''), 'store' => $context->storeName, 'price' => (string) ($product['price'] ?? ''), 'brand' => (string) ($product['brand'] ?? ''), 'sku' => (string) ($product['sku'] ?? ''), 'category' => (string) (($product['breadcrumbs'][count($product['breadcrumbs'] ?? []) - 2]['name'] ?? ''))]) : $this->seoTemplates->render($context->storeId, $context->locale, 'product', 'description', ['name' => (string) ($product['name'] ?? ''), 'store' => $context->storeName, 'price' => (string) ($product['price'] ?? ''), 'brand' => (string) ($product['brand'] ?? ''), 'sku' => (string) ($product['sku'] ?? ''), 'category' => (string) (($product['breadcrumbs'][count($product['breadcrumbs'] ?? []) - 2]['name'] ?? ''))], ((string) ($product['short_description'] ?? '') !== '' ? (string) $product['short_description'] : (string) ($product['description'] ?? ''))),
                'image' => $this->shareImage($baseUrl, [$displayImage, ...array_map(static fn (array $image): string => (string) ($image['url'] ?? ''), $product['images'])]),
                'type' => 'product',
                'canonical' => $baseUrl . $product['url'],
                'robots' => ($product['indexable'] ?? true) === false ? 'noindex,follow' : 'index,follow,max-image-preview:large',
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
        $brands = ProductCatalogFilter::ids($request->query->all()['brand'] ?? null);
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
            brandIds: $brands,
            inStockOnly: $request->query->getBoolean('in_stock', false),
            minPriceMinor: $min,
            maxPriceMinor: $max,
            sort: $sort,
            attributeFilters: $this->attributeFilters($request),
            minRating: max(0, min(5, (int) filter_var($request->query->get('rating'), FILTER_VALIDATE_INT, ['options' => ['default' => 0]]))),
        );
    }

    private function perPage(Request $request): int
    {
        $value = (int) filter_var($request->query->get('per_page'), FILTER_VALIDATE_INT, ['options' => ['default' => 24]]);
        return in_array($value, [12, 24, 48, 60], true) ? $value : 24;
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
        $allowed = ['q', 'in_stock', 'min_price', 'max_price', 'sort', 'rating', 'per_page'];
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
        $brands = ProductCatalogFilter::ids($request->query->all()['brand'] ?? null);
        if ($brands !== []) {
            $result['brand'] = $brands;
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
