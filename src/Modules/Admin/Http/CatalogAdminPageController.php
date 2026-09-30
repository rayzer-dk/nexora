<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Catalog\Application\CatalogMaintenanceService;
use Commerce\Modules\Catalog\Application\CategoryWriter;
use Commerce\Modules\Catalog\Application\Command\CreateCategoryCommand;
use Commerce\Modules\Catalog\Application\Command\CreateProductCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateCategoryCommand;
use Commerce\Modules\Catalog\Application\Command\UpdateProductCommand;
use Commerce\Modules\Catalog\Application\ProductWriter;
use Commerce\Modules\Catalog\Application\ProductVariantService;
use Commerce\Modules\Catalog\Application\ProductAttributeService;
use Commerce\Modules\Catalog\Application\ProductDocumentService;
use Commerce\Modules\Catalog\Infrastructure\DbalCatalogAdminQuery;
use Commerce\Modules\Media\Application\MediaImageService;
use Commerce\Modules\DigitalProduct\Application\ProductDigitalAssetService;
use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogAdminPageController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $context,
        private readonly DbalCatalogAdminQuery $query,
        private readonly CategoryWriter $categories,
        private readonly \Commerce\Modules\Catalog\Application\CategoryTextService $categoryTexts,
        private readonly \Commerce\Modules\CustomField\Application\CustomFieldService $customFields,
        private readonly ProductWriter $products,
        private readonly CatalogMaintenanceService $maintenance,
        private readonly MediaImageService $media,
        private readonly ProductVariantService $variants,
        private readonly ProductAttributeService $attributes,
        private readonly ProductDocumentService $documents,
        private readonly ProductDigitalAssetService $digitalAssets,
        private readonly Connection $db,
    ) {
    }

    #[Route('/admin/catalog/products', name: 'admin_catalog_products', methods: ['GET'])]
    public function products(Request $request): Response
    {
        $context = $this->context->resolve($request);
        $result = $this->query->products($context->storeId, $context->marketId, $context->locale, (int) $request->query->get('page', 1), 25, (string) $request->query->get('search', ''));
        foreach ($result['items'] as &$item) {
            $item['price_display'] = $item['amount_minor'] === null ? '—' : number_format(((int) $item['amount_minor']) / 100, 2, ',', ' ') . ' ' . ($item['currency'] ?? $context->currency);
        }
        $views=[];
        $selectedView=max(0,(int)$request->query->get('view',0));
        $allowedColumns=['name','sku','status','price','type','quality'];
        $activeColumns=array_values(array_intersect($allowedColumns,array_map('strval',(array)$request->query->all('columns'))));
        if($activeColumns===[])$activeColumns=$allowedColumns;
        try {
            $user=$this->getUser(); $adminId=$user instanceof AdminUser?$user->id:null;
            $rows=$this->db->fetchAllAssociative('SELECT id,name,filters_json,columns_json FROM mc_admin_saved_view WHERE store_id=? AND entity_type=? AND (admin_id=? OR admin_id IS NULL) ORDER BY id DESC LIMIT 20',[$context->storeId,'products',$adminId]);
            foreach($rows as $row){
                $filters=[];$columns=[];
                try{$decoded=json_decode((string)$row['filters_json'],true,16,JSON_THROW_ON_ERROR);if(is_array($decoded))$filters=$decoded;}catch(\Throwable){}
                try{$decoded=json_decode((string)$row['columns_json'],true,16,JSON_THROW_ON_ERROR);if(is_array($decoded))$columns=array_values(array_intersect($allowedColumns,array_map('strval',$decoded)));}catch(\Throwable){}
                if($columns===[])$columns=$allowedColumns;
                $views[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'search'=>(string)($filters['search']??''),'columns'=>$columns];
                if($selectedView===(int)$row['id']){$activeColumns=$columns;}
            }
        } catch (\Throwable) {}
        return $this->render('@storefront/admin/catalog/products.html.twig', ['result'=>$result,'search'=>(string)$request->query->get('search',''),'saved_views'=>$views,'active_columns'=>$activeColumns,'allowed_columns'=>$allowedColumns,'selected_view'=>$selectedView]);
    }

    #[Route('/admin/catalog/categories', name: 'admin_catalog_categories', methods: ['GET'])]
    public function categories(Request $request): Response
    {
        $context = $this->context->resolve($request);
        $result = $this->query->categories($context->storeId, $context->locale, (int) $request->query->get('page', 1), 25, (string) $request->query->get('search', ''));
        return $this->render('@storefront/admin/catalog/categories.html.twig', ['result' => $result, 'search' => (string) $request->query->get('search', '')]);
    }

    #[Route('/admin/catalog/categories/new', name: 'admin_catalog_category_new', methods: ['GET', 'POST'])]
    public function categoryNew(Request $request): Response
    {
        $context = $this->context->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_category_create', (string) $request->request->get('_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            } else {
                try {
                    $created = $this->categories->create(new CreateCategoryCommand(
                        $context->storeId, $context->marketId, $context->locale,
                        (string) $request->request->get('name', ''),
                        ($parent = (int) $request->request->get('parent_id', 0)) > 0 ? $parent : null,
                        trim((string) $request->request->get('slug', '')) ?: null,
                        (int) $request->request->get('sort_order', 0),
                    ));
                    $this->categoryTexts->save((int) $created['id'], $context->storeId, $context->locale, (string) $request->request->get('description', ''), (string) $request->request->get('description_bottom', ''));
                    $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.katehoriiu_stvoreno'));
                    return $this->redirectToRoute('admin_catalog_categories');
                } catch (\Throwable $e) {
                    $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.katehoriiu_ne_vdalosia_stvoryty'));
                }
            }
        }
        $choices = $this->query->categories($context->storeId, $context->locale, 1, 100, '')['items'];
        return $this->render('@storefront/admin/catalog/category_form.html.twig', ['categories' => $choices, 'category' => null, 'csrf_id' => 'admin_category_create']);
    }

    #[Route('/admin/catalog/products/new', name: 'admin_catalog_product_new', methods: ['GET', 'POST'])]
    public function productNew(Request $request): Response
    {
        $context = $this->context->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_product_create', (string) $request->request->get('_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            } else {
                try {
                    $categoryIds = array_values(array_filter(array_map('intval', $request->request->all('category_ids')), static fn (int $id): bool => $id > 0));
                    $created = $this->products->create(new CreateProductCommand(
                        storeId: $context->storeId, marketId: $context->marketId, locale: $context->locale,
                        name: (string) $request->request->get('name', ''), sku: (string) $request->request->get('sku', ''),
                        priceMinor: $this->moneyMinor((string) $request->request->get('price', '0')), currency: $context->currency,
                        stockQuantity: $this->quantity((string) $request->request->get('stock_quantity', '0')),
                        unitCode: (string) $request->request->get('unit_code', 'item'), productType: (string) $request->request->get('product_type', 'physical'),
                        categoryIds: $categoryIds, manualSlug: trim((string) $request->request->get('slug', '')) ?: null,
                        shortDescription: trim((string) $request->request->get('short_description', '')) ?: null,
                        description: trim((string) $request->request->get('description', '')) ?: null,
                        gtin: trim((string) $request->request->get('gtin', '')) ?: null, mpn: trim((string) $request->request->get('mpn', '')) ?: null,
                        brandId: ($brandId = (int) $request->request->get('brand_id', 0)) > 0 ? $brandId : null,
                        purchaseMode: (string) $request->request->get('purchase_mode', 'auto'),
                        purchaseButtonLabel: trim((string) $request->request->get('purchase_button_label', '')) ?: null,
                        purchaseEtaText: trim((string) $request->request->get('purchase_eta_text', '')) ?: null,
                    ));
                    $mediaErrors = $this->attachUploadedImages($request, $context->storeId, (int) $created['id'], (string) $request->request->get('name', ''));
                    $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.chernetku_tovaru_stvoreno'));
                    foreach ($mediaErrors as $mediaError) {
                        $this->addFlash('error', $mediaError);
                    }
                    return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $created['public_id']]);
                } catch (\Throwable $e) {
                    $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tovar_ne_vdalosia_stvoryty_perevirte_sku_ta_vvedeni_'));
                }
            }
        }
        $categories = $this->query->categories($context->storeId, $context->locale, 1, 100, '')['items'];
        $brands = $this->query->brands($context->storeId);
        return $this->render('@storefront/admin/catalog/product_form.html.twig', ['categories' => $categories, 'brands' => $brands, 'product' => null, 'images' => [], 'currency' => $context->currency, 'csrf_id' => 'admin_product_create']);
    }

    #[Route('/admin/catalog/categories/{publicId}/edit', name: 'admin_catalog_category_edit', methods: ['GET', 'POST'])]
    public function categoryEdit(string $publicId, Request $request): Response
    {
        $context = $this->context->resolve($request);
        $category = $this->query->categoryForEdit($context->storeId, $context->locale, $publicId);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_category_update_' . $publicId, (string) $request->request->get('_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            } else {
                try {
                    $this->categories->update(new UpdateCategoryCommand(
                        categoryId: (int) $category['id'], storeId: $context->storeId, marketId: $context->marketId, locale: $context->locale,
                        name: (string) $request->request->get('name', ''),
                        parentId: ($parent = (int) $request->request->get('parent_id', 0)) > 0 ? $parent : null,
                        manualSlug: trim((string) $request->request->get('slug', '')) ?: null,
                        sortOrder: (int) $request->request->get('sort_order', 0),
                        status: (string) $request->request->get('status', 'active'),
                    ));
                    $this->categoryTexts->save((int) $category['id'], $context->storeId, $context->locale, (string) $request->request->get('description', ''), (string) $request->request->get('description_bottom', ''));
                    $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.katehoriiu_onovleno'));
                    return $this->redirectToRoute('admin_catalog_categories');
                } catch (\Throwable $e) {
                    $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.katehoriiu_ne_vdalosia_onovyty'));
                }
            }
            $category = array_merge($category, [
                'name' => (string) $request->request->get('name', $category['name']),
                'parent_id' => (int) $request->request->get('parent_id', 0) ?: null,
                'slug' => (string) $request->request->get('slug', $category['slug'] ?? ''),
                'sort_order' => (int) $request->request->get('sort_order', $category['sort_order']),
                'status' => (string) $request->request->get('status', $category['status']),
                'description' => (string) $request->request->get('description', ''),
                'description_bottom' => (string) $request->request->get('description_bottom', ''),
            ]);
        }
        $choices = array_values(array_filter(
            $this->query->categories($context->storeId, $context->locale, 1, 100, '')['items'],
            static fn (array $item): bool => (int) $item['id'] !== (int) $category['id'],
        ));
        return $this->render('@storefront/admin/catalog/category_form.html.twig', [
            'categories' => $choices, 'category' => $category, 'csrf_id' => 'admin_category_update_' . $publicId,
        ]);
    }

    #[Route('/admin/catalog/products/{publicId}/edit', name: 'admin_catalog_product_edit', methods: ['GET', 'POST'])]
    public function productEdit(string $publicId, Request $request): Response
    {
        $context = $this->context->resolve($request);
        $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_product_update_' . $publicId, (string) $request->request->get('_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            } else {
                try {
                    $categoryIds = array_values(array_filter(array_map('intval', $request->request->all('category_ids')), static fn (int $id): bool => $id > 0));
                    $this->products->update(new UpdateProductCommand(
                        productId: (int) $product['id'], storeId: $context->storeId, marketId: $context->marketId, locale: $context->locale,
                        name: (string) $request->request->get('name', ''), sku: (string) $request->request->get('sku', ''),
                        priceMinor: $this->moneyMinor((string) $request->request->get('price', '0')), currency: $context->currency,
                        stockQuantity: $this->quantity((string) $request->request->get('stock_quantity', '0')),
                        unitCode: (string) $request->request->get('unit_code', 'item'), categoryIds: $categoryIds,
                        manualSlug: trim((string) $request->request->get('slug', '')) ?: null,
                        shortDescription: trim((string) $request->request->get('short_description', '')) ?: null,
                        description: trim((string) $request->request->get('description', '')) ?: null,
                        gtin: trim((string) $request->request->get('gtin', '')) ?: null,
                        mpn: trim((string) $request->request->get('mpn', '')) ?: null,
                        status: (string) $request->request->get('status', 'draft'),
                        brandId: ($brandId = (int) $request->request->get('brand_id', 0)) > 0 ? $brandId : null,
                        purchaseMode: (string) $request->request->get('purchase_mode', 'auto'),
                        purchaseButtonLabel: trim((string) $request->request->get('purchase_button_label', '')) ?: null,
                        purchaseEtaText: trim((string) $request->request->get('purchase_eta_text', '')) ?: null,
                    ));
                    $this->updateAttachedImageSettings($request, (int) $product['id']);
                    $mediaErrors = $this->attachUploadedImages($request, $context->storeId, (int) $product['id'], (string) $request->request->get('name', $product['name']));
                    $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tovar_onovleno'));
                    foreach ($mediaErrors as $mediaError) {
                        $this->addFlash('error', $mediaError);
                    }
                    return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
                } catch (\Throwable $e) {
                    $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tovar_ne_vdalosia_onovyty'));
                }
            }
            $product = array_merge($product, [
                'name' => (string) $request->request->get('name', $product['name']), 'sku' => (string) $request->request->get('sku', $product['sku']),
                'slug' => (string) $request->request->get('slug', $product['slug'] ?? ''), 'short_description' => (string) $request->request->get('short_description', ''),
                'description' => (string) $request->request->get('description', ''), 'gtin' => (string) $request->request->get('gtin', ''),
                'mpn' => (string) $request->request->get('mpn', ''), 'sale_unit_code' => (string) $request->request->get('unit_code', 'item'),
                'stock_quantity' => (string) $request->request->get('stock_quantity', '0'), 'price_input' => (string) $request->request->get('price', '0'),
                'category_ids' => array_values(array_map('intval', $request->request->all('category_ids'))), 'status' => (string) $request->request->get('status', 'draft'),
                'brand_id' => (int) $request->request->get('brand_id', 0) ?: null,
                'purchase_mode' => (string) $request->request->get('purchase_mode', 'auto'),
                'purchase_button_label' => (string) $request->request->get('purchase_button_label', ''),
                'purchase_eta_text' => (string) $request->request->get('purchase_eta_text', ''),
            ]);
        }
        if (!isset($product['price_input'])) {
            $product['price_input'] = $product['amount_minor'] === null ? '0.00' : $this->moneyDisplay((int) $product['amount_minor']);
        }
        if ($product['stock_quantity'] === null) {
            $product['stock_quantity'] = '0.000000';
        }
        $categories = $this->query->categories($context->storeId, $context->locale, 1, 100, '')['items'];
        $brands = $this->query->brands($context->storeId);
        return $this->render('@storefront/admin/catalog/product_form.html.twig', [
            'categories' => $categories,
            'brands' => $brands,
            'product' => $product,
            'images' => $this->media->productImages((int) $product['id']),
            'variants' => $this->query->variantsForEdit((int) $product['id'], $context->storeId, $context->marketId),
            'attributes' => $this->query->productAttributesForEdit((int) $product['id'], $context->locale),
            'documents' => $this->query->productDocumentsForEdit((int) $product['id']),
            'custom_definitions' => $this->customFields->definitions($context->storeId),
            'custom_values' => $this->customFields->values($context->storeId, (int) $product['id']),
            'digital_assets' => $product['product_type'] === 'digital' ? $this->digitalAssets->forProduct((int) $product['id']) : [],
            'locales' => $this->query->storeLocales($context->storeId),
            'currency' => $context->currency,
            'csrf_id' => 'admin_product_update_' . $publicId,
        ]);
    }

    #[Route('/admin/catalog/products/{publicId}/variants/create', name: 'admin_catalog_product_variant_create', methods: ['POST'])]
    public function productVariantCreate(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_variants_' . $publicId, (string) $request->request->get('_variant_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.sesiiu_formy_variantiv_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $data = $request->request->all('new_variant');
            if (!is_array($data)) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.dani_novoho_varianta_vidsutni'));
            }
            $this->variants->create(
                (int) $product['id'], $context->storeId, $context->marketId,
                (string) ($data['sku'] ?? ''), $this->moneyMinor((string) ($data['price'] ?? '0')),
                $this->quantity((string) ($data['stock_quantity'] ?? '0')), (string) ($data['unit_code'] ?? 'item'),
                trim((string) ($data['gtin'] ?? '')) ?: null, trim((string) ($data['mpn'] ?? '')) ?: null,
                isset($data['allow_backorder']) && (string) $data['allow_backorder'] === '1',
                $this->nullableFloat($data['weight_kg'] ?? null), $this->nullableInt($data['length_mm'] ?? null),
                $this->nullableInt($data['width_mm'] ?? null), $this->nullableInt($data['height_mm'] ?? null),
            );
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.variant_stvoreno_osnovnyi_tovar_zalyshyvsia_nezminny'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.variant_ne_vdalosia_stvoryty_tovar_ne_zmineno'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/variants/{variantId}/update', name: 'admin_catalog_product_variant_update', methods: ['POST'], requirements: ['variantId' => '\\d+'])]
    public function productVariantUpdate(string $publicId, int $variantId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_variants_' . $publicId, (string) $request->request->get('_variant_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.sesiiu_formy_variantiv_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $all = $request->request->all('variant');
            $data = is_array($all) && is_array($all[$variantId] ?? null) ? $all[$variantId] : null;
            if (!is_array($data)) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.dani_varianta_vidsutni'));
            }
            $this->variants->update(
                (int) $product['id'], $variantId, $context->storeId, $context->marketId,
                (string) ($data['sku'] ?? ''), $this->moneyMinor((string) ($data['price'] ?? '0')),
                $this->quantity((string) ($data['stock_quantity'] ?? '0')), (string) ($data['unit_code'] ?? 'item'),
                (string) ($data['status'] ?? 'active'), trim((string) ($data['gtin'] ?? '')) ?: null,
                trim((string) ($data['mpn'] ?? '')) ?: null, isset($data['allow_backorder']) && (string) $data['allow_backorder'] === '1',
                $this->nullableFloat($data['weight_kg'] ?? null), $this->nullableInt($data['length_mm'] ?? null),
                $this->nullableInt($data['width_mm'] ?? null), $this->nullableInt($data['height_mm'] ?? null),
            );
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.variant_onovleno'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.variant_ne_vdalosia_onovyty_inshi_dani_tovaru_ne_zmi'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/variants/{variantId}/delete', name: 'admin_catalog_product_variant_delete', methods: ['POST'], requirements: ['variantId' => '\\d+'])]
    public function productVariantDelete(string $publicId, int $variantId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_variants_' . $publicId, (string) $request->request->get('_variant_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.sesiiu_formy_variantiv_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $this->variants->delete((int) $product['id'], $variantId, $context->storeId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.nevykorystanyi_variant_vydaleno'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.variant_ne_vdalosia_vydalyty'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/attributes', name: 'admin_catalog_product_attributes_update', methods: ['POST'])]
    public function productAttributesUpdate(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_attributes_' . $publicId, (string) $request->request->get('_attributes_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.sesiiu_kharakterystyk_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $values = $request->request->all('attribute');
            $this->attributes->saveProductValues((int) $product['id'], $context->locale, is_array($values) ? $values : []);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.kharakterystyky_zberezheno'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.kharakterystyky_ne_vdalosia_zberehty_osnovni_dani_to'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/digital/upload', name: 'admin_catalog_product_digital_upload', methods: ['POST'])]
    public function productDigitalUpload(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_digital_' . $publicId, (string) $request->request->get('_digital_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $file = $request->files->get('digital_file');
            if (!$file instanceof UploadedFile) throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.oberit_fail_tsyfrovoho_tovaru'));
            $daysRaw = trim((string) $request->request->get('digital_access_days', '365'));
            $this->digitalAssets->upload(
                (int) $product['id'], $file, (string) $request->request->get('digital_title', ''),
                (int) $request->request->get('digital_max_downloads', 5), $daysRaw === '' ? null : (int) $daysRaw,
            );
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tsyfrovyi_fail_zberezheno_u_pryvatnomu_skhovyshchi'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tsyfrovyi_fail_ne_vdalosia_dodaty'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/digital/{assetPublicId}/deactivate', name: 'admin_catalog_product_digital_deactivate', methods: ['POST'])]
    public function productDigitalDeactivate(string $publicId, string $assetPublicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_digital_' . $publicId, (string) $request->request->get('_digital_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $this->digitalAssets->deactivate((int) $product['id'], $assetPublicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.fail_bilshe_ne_vydavatymetsia_v_novykh_zamovlenniakh'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.ne_vdalosia_zminyty_tsyfrovyi_fail'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/documents/upload', name: 'admin_catalog_product_document_upload', methods: ['POST'])]
    public function productDocumentUpload(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_documents_' . $publicId, (string) $request->request->get('_document_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.sesiiu_dokumentiv_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $file = $request->files->get('document_file');
            if (!$file instanceof UploadedFile) {
                throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.oberit_pdf_abo_txt_fail'));
            }
            $locale = (string) $request->request->get('document_locale', '');
            $this->documents->uploadAndAttach(
                (int) $product['id'], $file, (string) $request->request->get('document_title', ''),
                $locale === '' ? null : $locale, (string) $request->request->get('document_type', 'document'),
                (int) $request->request->get('document_sort', 100),
            );
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.dokument_perevireno_ta_dodano_do_tovaru'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.dokument_ne_vdalosia_dodaty_tovar_prodovzhuie_pratsi'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/documents/{documentPublicId}/remove', name: 'admin_catalog_product_document_remove', methods: ['POST'])]
    public function productDocumentRemove(string $publicId, string $documentPublicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_documents_' . $publicId, (string) $request->request->get('_document_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.sesiiu_dokumentiv_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $this->documents->remove((int) $product['id'], $documentPublicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.dokument_vidviazano_vid_tovaru'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.dokument_ne_vdalosia_vydalyty'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/media/{assetId}/primary', name: 'admin_catalog_product_media_primary', methods: ['POST'], requirements: ['assetId' => '\d+'])]
    public function productMediaPrimary(string $publicId, int $assetId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_media_' . $publicId, (string) $request->request->get('_media_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $this->media->setPrimary((int) $product['id'], $assetId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.holovne_zobrazhennia_zmineno'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.ne_vdalosia_zminyty_holovne_zobrazhennia'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/media/{assetId}/remove', name: 'admin_catalog_product_media_remove', methods: ['POST'], requirements: ['assetId' => '\d+'])]
    public function productMediaRemove(string $publicId, int $assetId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_media_' . $publicId, (string) $request->request->get('_media_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
        }
        try {
            $context = $this->context->resolve($request);
            $product = $this->query->productForEdit($context->storeId, $context->marketId, $context->locale, $publicId);
            $this->media->detachFromProduct((int) $product['id'], $assetId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.zobrazhennia_vidviazano_vid_tovaru_fail_bude_bezpech'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.ne_vdalosia_vydalyty_zobrazhennia'));
        }
        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    #[Route('/admin/catalog/products/{publicId}/duplicate', name: 'admin_catalog_product_duplicate', methods: ['POST'])]
    public function productDuplicate(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_duplicate_' . $publicId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_products');
        }

        try {
            $context = $this->context->resolve($request);
            $created = $this->maintenance->duplicateProductDraft($context->storeId, $context->marketId, $context->locale, $publicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.stvoreno_kopiiu_tovaru_iak_chernetku_sku_ta_url_sfor'));
            return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $created['public_id']]);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tovar_ne_vdalosia_skopiiuvaty'));
            return $this->redirectToRoute('admin_catalog_products');
        }
    }

    #[Route('/admin/catalog/products/{publicId}/delete', name: 'admin_catalog_product_delete', methods: ['POST'])]
    public function productDelete(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_product_delete_' . $publicId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_products');
        }
        try {
            $context = $this->context->resolve($request);
            $this->maintenance->deleteProduct($context->storeId, $publicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tovar_vydaleno_istorychni_tovary_zamovlen_vydaliaty_'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tovar_ne_vdalosia_vydalyty'));
        }
        return $this->redirectToRoute('admin_catalog_products');
    }

    #[Route('/admin/catalog/categories/{publicId}/delete', name: 'admin_catalog_category_delete', methods: ['POST'])]
    public function categoryDelete(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_category_delete_' . $publicId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_catalog_categories');
        }
        try {
            $context = $this->context->resolve($request);
            $this->maintenance->deleteCategory($context->storeId, $publicId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.porozhniu_katehoriiu_vydaleno'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.katehoriiu_ne_vdalosia_vydalyty'));
        }
        return $this->redirectToRoute('admin_catalog_categories');
    }

    private function updateAttachedImageSettings(Request $request, int $productId): void
    {
        $sorts = $request->request->all('media_sort');
        $alts = $request->request->all('media_alt');
        $focalX = $request->request->all('media_focal_x');
        $focalY = $request->request->all('media_focal_y');
        if (!is_array($sorts)) {
            return;
        }
        $allowedIds = array_fill_keys(array_map(static fn (array $image): int => (int) $image['id'], $this->media->productImages($productId)), true);
        foreach ($sorts as $assetIdRaw => $sortRaw) {
            $assetId = (int) $assetIdRaw;
            if ($assetId < 1 || !isset($allowedIds[$assetId])) {
                continue;
            }
            $this->media->updateProductImage(
                $productId,
                $assetId,
                (int) $sortRaw,
                isset($alts[$assetIdRaw]) ? (string) $alts[$assetIdRaw] : null,
                $this->focalValue($focalX[$assetIdRaw] ?? 0.5),
                $this->focalValue($focalY[$assetIdRaw] ?? 0.5),
            );
        }
    }

    private function focalValue(mixed $value): float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '' || !is_numeric($value)) {
            return 0.5;
        }
        return max(0.0, min(1.0, (float) $value));
    }

    /** @return list<string> */
    private function attachUploadedImages(Request $request, int $storeId, int $productId, string $productName): array
    {
        $files = $request->files->all('images');
        if (!is_array($files) || $files === []) {
            return [];
        }
        $errors = [];
        $existing = $this->media->productImages($productId);
        $sort = count($existing) * 10;
        $hasPrimary = array_filter($existing, static fn (array $image): bool => ($image['role'] ?? '') === 'primary') !== [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $uploaded = $this->media->upload($file, $storeId);
                $role = $hasPrimary ? 'gallery' : 'primary';
                $this->media->attachToProduct($productId, $uploaded->assetId, $role, $sort, $productName);
                $hasPrimary = true;
                $sort += 10;
            } catch (\Throwable $e) {
                $errors[] = $e instanceof \InvalidArgumentException || $e instanceof \DomainException ? $e->getMessage() : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.odne_iz_zobrazhen_ne_vdalosia_obrobyty_tovar_zberezh');
            }
        }
        return $errors;
    }

    private function moneyDisplay(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function moneyMinor(string $value): int
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.tsina_maie_buty_dodatnym_chyslom_z_tochnistiu_do_kop'));
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function quantity(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/', $value)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.zalyshok_maie_buty_nevidiemnym_chyslom'));
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return $whole . '.' . str_pad(substr($fraction, 0, 6), 6, '0');
    }


    private function nullableFloat(mixed $value): ?float
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        $value = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.vaha_maie_buty_chyslom'));
        }
        return (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        $raw = trim((string) $value);
        if (preg_match('/^\d{1,10}$/D', $raw) !== 1) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.catalogadminpagecontroller.rozmir_maie_buty_tsilym_chyslom_u_milimetrakh'));
        }
        return (int) $raw;
    }
}
