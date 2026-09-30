<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\CustomField\Application\CustomFieldService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Definitions of extra product fields, and saving their values from the product edit page. */
final class CustomFieldAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly CustomFieldService $fields, private readonly \Doctrine\DBAL\Connection $db)
    {
    }

    #[Route('/admin/catalog/fields', name: 'admin_catalog_fields', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('@storefront/admin/catalog/fields.html.twig', [
            'definitions' => $this->fields->definitions($this->contexts->resolve($request)->storeId),
            'types' => CustomFieldService::TYPES,
        ]);
    }

    #[Route('/admin/catalog/fields/save', name: 'admin_catalog_fields_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $this->guard($request);
        $r = $request->request;
        try {
            $this->fields->saveDefinition($this->contexts->resolve($request)->storeId, (string) $r->get('code', ''), (string) $r->get('label', ''), (string) $r->get('field_type', 'text'), $r->has('show_on_storefront'), $r->getInt('sort_order', 100));
            $this->addFlash('success', CanonicalUiText::get('admin.fields.saved'));
        } catch (\InvalidArgumentException|\DomainException) {
            $this->addFlash('error', CanonicalUiText::get('admin.fields.invalid'));
        }

        return $this->redirectToRoute('admin_catalog_fields');
    }

    #[Route('/admin/catalog/fields/{id}/delete', name: 'admin_catalog_fields_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->fields->deleteDefinition($this->contexts->resolve($request)->storeId, $id);

        return $this->redirectToRoute('admin_catalog_fields');
    }

    #[Route('/admin/catalog/products/{publicId}/fields', name: 'admin_catalog_product_fields_save', methods: ['POST'])]
    public function saveValues(string $publicId, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_product_fields_' . $publicId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $storeId = $this->contexts->resolve($request)->storeId;
        try {
            $binary = \Symfony\Component\Uid\Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            throw $this->createNotFoundException();
        }
        $productId = $this->db->fetchOne('SELECT p.id FROM mc_product p JOIN mc_store_product sp ON sp.product_id=p.id AND sp.store_id=? WHERE p.public_id=? LIMIT 1', [$storeId, $binary]);
        if ($productId === false) {
            throw $this->createNotFoundException();
        }
        $this->fields->saveValues($storeId, (int) $productId, $request->request->all('field'));
        $this->addFlash('success', CanonicalUiText::get('admin.fields.values_saved'));

        return $this->redirectToRoute('admin_catalog_product_edit', ['publicId' => $publicId]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_fields', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
