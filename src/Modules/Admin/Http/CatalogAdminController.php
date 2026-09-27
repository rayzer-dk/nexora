<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Security\AdminCsrfGuard;
use Commerce\Modules\Catalog\Application\CatalogMaintenanceService;
use Commerce\Modules\Catalog\Application\CategoryWriter;
use Commerce\Modules\Catalog\Application\Command\CreateCategoryCommand;
use Commerce\Modules\Catalog\Application\Command\CreateProductCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateCategoryCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Catalog\Infrastructure\DbalCatalogAdminQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $context,
        private readonly AdminCsrfGuard $csrf,
        private readonly DbalCatalogAdminQuery $query,
        private readonly CategoryWriter $categories,
        private readonly ProductWriter $products,
        private readonly CatalogMaintenanceService $maintenance,
    ) {
    }

    #[Route('/admin/api/catalog/products', name: 'admin_catalog_products_list', methods: ['GET'])]
    public function productsList(Request $request): JsonResponse
    {
        try {
            $context = $this->context->resolve($request);
            return $this->json($this->query->products($context->storeId, $context->marketId, $context->locale, (int) $request->query->get('page', 1), (int) $request->query->get('limit', 25), (string) $request->query->get('search', '')));
        } catch (\Throwable $e) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed')], 500);
        }
    }

    #[Route('/admin/api/catalog/categories', name: 'admin_catalog_categories_list', methods: ['GET'])]
    public function categoriesList(Request $request): JsonResponse
    {
        try {
            $context = $this->context->resolve($request);
            return $this->json($this->query->categories($context->storeId, $context->locale, (int) $request->query->get('page', 1), (int) $request->query->get('limit', 25), (string) $request->query->get('search', '')));
        } catch (\Throwable $e) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed')], 500);
        }
    }

    #[Route('/admin/api/catalog/categories', name: 'admin_catalog_categories_create', methods: ['POST'])]
    public function categoryCreate(Request $request): JsonResponse
    {
        try {
            $this->csrf->assertValid($request);
            $context = $this->context->resolve($request);
            $data = $request->toArray();
            $result = $this->categories->create(new CreateCategoryCommand(
                $context->storeId, $context->marketId, $context->locale, (string) ($data['name'] ?? ''),
                isset($data['parent_id']) ? (int) $data['parent_id'] : null,
                isset($data['slug']) && trim((string) $data['slug']) !== '' ? (string) $data['slug'] : null,
                (int) ($data['sort_order'] ?? 0),
            ));
            return $this->json($result, 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('admin.catalog.error.category_create')], 500);
        }
    }

    #[Route('/admin/api/catalog/categories/{publicId}', name: 'admin_catalog_categories_update', methods: ['PATCH'])]
    public function categoryUpdate(string $publicId, Request $request): JsonResponse
    {
        try {
            $this->csrf->assertValid($request);
            $context = $this->context->resolve($request);
            $current = $this->query->categoryForEdit($context->storeId, $context->locale, $publicId);
            $data = $request->toArray();
            $result = $this->categories->update(new UpdateCategoryCommand(
                categoryId: (int) $current['id'], storeId: $context->storeId, marketId: $context->marketId, locale: $context->locale,
                name: (string) ($data['name'] ?? $current['name']),
                parentId: array_key_exists('parent_id', $data) ? ((int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null) : ($current['parent_id'] === null ? null : (int) $current['parent_id']),
                manualSlug: array_key_exists('slug', $data) && trim((string) $data['slug']) !== '' ? (string) $data['slug'] : (string) ($current['slug'] ?? ''),
                sortOrder: (int) ($data['sort_order'] ?? $current['sort_order']), status: (string) ($data['status'] ?? $current['status']),
            ));
            return $this->json($result);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('admin.catalog.error.category_update')], 500);
        }
    }

    #[Route('/admin/api/catalog/products/{publicId}', name: 'admin_catalog_products_update', methods: ['PATCH'])]
    public function productUpdate(string $publicId, Request $request): JsonResponse
    {
        try {
            $this->csrf->assertValid($request);
            $context = $this->context->resolve($request);
            $current = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $data = $request->toArray();
            $categoryIds = array_key_exists('category_ids', $data) && is_array($data['category_ids'])
                ? array_values(array_map('intval', $data['category_ids'])) : $current['category_ids'];
            $result = $this->products->update(new UpdateProductCommand(
                productId: (int) $current['id'], storeId: $context->storeId, marketId: $context->marketId, locale: $context->locale,
                name: (string) ($data['name'] ?? $current['name']), sku: (string) ($data['sku'] ?? $current['sku']),
                priceMinor: (int) ($data['price_minor'] ?? $current['amount_minor'] ?? 0), currency: (string) ($data['currency'] ?? $current['currency'] ?? $context->currency),
                stockQuantity: (string) ($data['stock_quantity'] ?? $current['stock_quantity'] ?? '0.000000'), unitCode: (string) ($data['unit_code'] ?? $current['sale_unit_code'] ?? 'item'),
                categoryIds: $categoryIds, manualSlug: array_key_exists('slug', $data) && trim((string) $data['slug']) !== '' ? (string) $data['slug'] : (string) ($current['slug'] ?? ''),
                shortDescription: array_key_exists('short_description', $data) ? (string) $data['short_description'] : ($current['short_description'] === null ? null : (string) $current['short_description']),
                description: array_key_exists('description', $data) ? (string) $data['description'] : ($current['description'] === null ? null : (string) $current['description']),
                gtin: array_key_exists('gtin', $data) ? (trim((string) $data['gtin']) ?: null) : ($current['gtin'] === null ? null : (string) $current['gtin']),
                mpn: array_key_exists('mpn', $data) ? (trim((string) $data['mpn']) ?: null) : ($current['mpn'] === null ? null : (string) $current['mpn']),
                status: (string) ($data['status'] ?? $current['status']),
                brandId: array_key_exists('brand_id', $data) ? (((int) $data['brand_id']) > 0 ? (int) $data['brand_id'] : null) : ($current['brand_id'] === null ? null : (int) $current['brand_id']),
            ));
            return $this->json($result);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('admin.catalog.error.product_update')], 500);
        }
    }

    #[Route('/admin/api/catalog/products', name: 'admin_catalog_products_create', methods: ['POST'])]
    public function productCreate(Request $request): JsonResponse
    {
        try {
            $this->csrf->assertValid($request);
            $context = $this->context->resolve($request);
            $data = $request->toArray();
            $categoryIds = array_values(array_map('intval', is_array($data['category_ids'] ?? null) ? $data['category_ids'] : []));
            $result = $this->products->create(new CreateProductCommand(
                storeId: $context->storeId,
                marketId: $context->marketId,
                locale: $context->locale,
                name: (string) ($data['name'] ?? ''),
                sku: (string) ($data['sku'] ?? ''),
                priceMinor: (int) ($data['price_minor'] ?? 0),
                currency: (string) ($data['currency'] ?? $context->currency),
                stockQuantity: (string) ($data['stock_quantity'] ?? '0.000000'),
                unitCode: (string) ($data['unit_code'] ?? 'item'),
                productType: (string) ($data['product_type'] ?? 'physical'),
                categoryIds: $categoryIds,
                manualSlug: isset($data['slug']) && trim((string) $data['slug']) !== '' ? (string) $data['slug'] : null,
                shortDescription: isset($data['short_description']) ? (string) $data['short_description'] : null,
                description: isset($data['description']) ? (string) $data['description'] : null,
                gtin: isset($data['gtin']) && trim((string) $data['gtin']) !== '' ? (string) $data['gtin'] : null,
                mpn: isset($data['mpn']) && trim((string) $data['mpn']) !== '' ? (string) $data['mpn'] : null,
                brandId: isset($data['brand_id']) && (int) $data['brand_id'] > 0 ? (int) $data['brand_id'] : null,
            ));
            return $this->json($result, 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('admin.catalog.error.product_create')], 500);
        }
    }
    #[Route('/admin/api/catalog/products/{publicId}/duplicate', name: 'admin_catalog_products_duplicate', methods: ['POST'])]
    public function productDuplicate(string $publicId, Request $request): JsonResponse
    {
        try {
            $this->csrf->assertValid($request);
            $context = $this->context->resolve($request);
            return $this->json($this->maintenance->duplicateProductDraft($context->storeId, $context->marketId, $context->locale, $publicId), 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('admin.catalog.error.product_duplicate')], 500);
        }
    }

    #[Route('/admin/api/catalog/products/{publicId}', name: 'admin_catalog_products_delete', methods: ['DELETE'])]
    public function productDelete(string $publicId, Request $request): JsonResponse
    {
        try {
            $this->csrf->assertValid($request);
            $context = $this->context->resolve($request);
            $this->maintenance->deleteProduct($context->storeId, $publicId);
            return $this->json(['deleted' => true]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('admin.catalog.error.product_delete')], 500);
        }
    }

    #[Route('/admin/api/catalog/categories/{publicId}', name: 'admin_catalog_categories_delete', methods: ['DELETE'])]
    public function categoryDelete(string $publicId, Request $request): JsonResponse
    {
        try {
            $this->csrf->assertValid($request);
            $context = $this->context->resolve($request);
            $this->maintenance->deleteCategory($context->storeId, $publicId);
            return $this->json(['deleted' => true]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return $this->json(['error' => \Commerce\Core\I18n\CanonicalUiText::get('admin.catalog.error.category_delete')], 500);
        }
    }

}
