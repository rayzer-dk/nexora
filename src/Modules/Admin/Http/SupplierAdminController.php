<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Supplier\Application\SupplierFeedParser;
use Commerce\Modules\Supplier\Application\SupplierService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Supplier price and stock feeds: connect a feed, preview what would change, apply it by hand or automatically. */
final class SupplierAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly SupplierService $suppliers)
    {
    }

    #[Route('/admin/catalog/suppliers', name: 'admin_catalog_suppliers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $selected = null;
        $id = $request->query->getInt('id');
        if ($id > 0) {
            $selected = $this->suppliers->get($context->storeId, $id);
        }

        return $this->render('@storefront/admin/catalog/suppliers.html.twig', [
            'suppliers' => $this->suppliers->all($context->storeId),
            's' => $selected,
            'mapping' => $selected !== null && is_string($selected['mapping_json'] ?? null) ? (array) json_decode((string) $selected['mapping_json'], true) : [],
            'formats' => SupplierFeedParser::FORMATS,
            'changes' => $selected !== null ? $this->suppliers->items((int) $selected['id'], 'changes') : [],
            'changes_total' => $selected !== null ? $this->suppliers->pendingCount((int) $selected['id'], 'changes') : 0,
            'new_offers' => $selected !== null ? $this->suppliers->items((int) $selected['id'], 'new', 50) : [],
            'new_total' => $selected !== null ? $this->suppliers->pendingCount((int) $selected['id'], 'new') : 0,
            'runs' => $selected !== null ? $this->suppliers->runs((int) $selected['id']) : [],
            'currency' => $context->currency,
        ]);
    }

    #[Route('/admin/catalog/suppliers/save', name: 'admin_catalog_supplier_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $this->guard($request);
        $id = $request->request->getInt('id') ?: null;
        try {
            $saved = $this->suppliers->save($context->storeId, $id, $request->request->all() + ['_file' => $request->files->get('feed_file')]);
            $this->addFlash('success', CanonicalUiText::get('admin.suppliers.saved'));

            return $this->redirectToRoute('admin_catalog_suppliers', ['id' => $saved]);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_catalog_suppliers', $id !== null ? ['id' => $id] : []);
    }

    #[Route('/admin/catalog/suppliers/{id}/run', name: 'admin_catalog_supplier_run', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function run(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $this->guard($request);
        try {
            $stats = $this->suppliers->run($context->storeId, $id);
            $this->addFlash('success', CanonicalUiText::get('admin.suppliers.run_done', ['rows' => (string) $stats['rows'], 'changed' => (string) $stats['changed'], 'new' => (string) $stats['new'], 'applied' => (string) $stats['applied']]));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_catalog_suppliers', ['id' => $id]);
    }

    #[Route('/admin/catalog/suppliers/{id}/apply', name: 'admin_catalog_supplier_apply', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function apply(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $this->guard($request);
        $what = in_array($request->request->get('what'), ['price', 'stock'], true) ? (string) $request->request->get('what') : 'both';
        $ids = $request->request->get('scope') === 'all' ? null : array_map('intval', (array) $request->request->all('item'));
        $count = $this->suppliers->applyItems($context->storeId, $context->marketId, $id, $ids, $what);
        $this->addFlash('success', CanonicalUiText::get('admin.suppliers.applied', ['count' => (string) $count]));

        return $this->redirectToRoute('admin_catalog_suppliers', ['id' => $id]);
    }

    #[Route('/admin/catalog/suppliers/{id}/skip', name: 'admin_catalog_supplier_skip', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function skip(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $this->guard($request);
        if ($this->suppliers->get($context->storeId, $id) !== null) {
            $this->suppliers->skipItems($id, array_map('intval', (array) $request->request->all('item')));
        }

        return $this->redirectToRoute('admin_catalog_suppliers', ['id' => $id]);
    }

    #[Route('/admin/catalog/suppliers/{id}/delete', name: 'admin_catalog_supplier_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $this->guard($request);
        $this->suppliers->delete($context->storeId, $id);
        $this->addFlash('success', CanonicalUiText::get('admin.suppliers.deleted'));

        return $this->redirectToRoute('admin_catalog_suppliers');
    }

    #[Route('/admin/catalog/suppliers/{id}/new-offers.csv', name: 'admin_catalog_supplier_new_csv', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function newCsv(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);

        return new Response($this->suppliers->newOffersCsv($context->storeId, $id), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="supplier-new-offers.csv"',
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_suppliers', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
