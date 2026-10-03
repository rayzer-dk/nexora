<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Catalog\Application\CatalogTranslationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** One page per product or category with the texts in every store language, optionally translated by the AI assistant. */
final class CatalogTranslationAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly CatalogTranslationService $translations)
    {
    }

    #[Route('/admin/catalog/products/{publicId}/translations', name: 'admin_catalog_product_translations', methods: ['GET'])]
    public function product(Request $request, string $publicId): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $entity = $this->translations->product($storeId, $publicId);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@storefront/admin/catalog/translations.html.twig', $this->view($storeId, 'product', $entity, $this->translations->productTexts($storeId, $entity['id']), array_keys(CatalogTranslationService::PRODUCT_FIELDS)));
    }

    #[Route('/admin/catalog/categories/{publicId}/translations', name: 'admin_catalog_category_translations', methods: ['GET'])]
    public function category(Request $request, string $publicId): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $entity = $this->translations->category($storeId, $publicId);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@storefront/admin/catalog/translations.html.twig', $this->view($storeId, 'category', $entity, $this->translations->categoryTexts($storeId, $entity['id']), array_keys(CatalogTranslationService::CATEGORY_FIELDS)));
    }

    #[Route('/admin/catalog/products/{publicId}/translations/{locale}', name: 'admin_catalog_product_translation_save', methods: ['POST'], requirements: ['locale' => '[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?'])]
    public function saveProduct(Request $request, string $publicId, string $locale): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $entity = $this->translations->product($storeId, $publicId);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }
        $this->guard($request, $publicId);
        try {
            $this->translations->saveProduct($storeId, $entity['id'], $locale, $request->request->all());
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => true, 'message' => CanonicalUiText::get('admin.translations.saved')]);
            }
            $this->addFlash('success', CanonicalUiText::get('admin.translations.saved'));
        } catch (\InvalidArgumentException $e) {
            $message = CanonicalUiText::get('admin.translations.error.' . $e->getMessage());
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', $message);
        }

        return $this->redirectToRoute('admin_catalog_product_translations', ['publicId' => $publicId, '_fragment' => 'lang-' . $locale]);
    }

    #[Route('/admin/catalog/categories/{publicId}/translations/{locale}', name: 'admin_catalog_category_translation_save', methods: ['POST'], requirements: ['locale' => '[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?'])]
    public function saveCategory(Request $request, string $publicId, string $locale): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $entity = $this->translations->category($storeId, $publicId);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }
        $this->guard($request, $publicId);
        try {
            $this->translations->saveCategory($storeId, $entity['id'], $locale, $request->request->all());
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => true, 'message' => CanonicalUiText::get('admin.translations.saved')]);
            }
            $this->addFlash('success', CanonicalUiText::get('admin.translations.saved'));
        } catch (\InvalidArgumentException $e) {
            $message = CanonicalUiText::get('admin.translations.error.' . $e->getMessage());
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', $message);
        }

        return $this->redirectToRoute('admin_catalog_category_translations', ['publicId' => $publicId, '_fragment' => 'lang-' . $locale]);
    }

    /**
     * @param array{id:int,public_id:string,name:string} $entity
     * @param array<string,array<string,string>> $texts
     * @param list<string> $fields
     * @return array<string,mixed>
     */
    private function view(int $storeId, string $kind, array $entity, array $texts, array $fields): array
    {
        $locales = $this->translations->locales($storeId);
        usort($locales, static fn (array $a, array $b): int => (int) $b['is_default'] <=> (int) $a['is_default']);

        return ['kind' => $kind, 'entity' => $entity, 'texts' => $texts, 'fields' => $fields, 'locales' => $locales, 'csrf_id' => 'admin_translation_' . $entity['public_id']];
    }

    private function guard(Request $request, string $publicId): void
    {
        if (!$this->isCsrfTokenValid('admin_translation_' . $publicId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
