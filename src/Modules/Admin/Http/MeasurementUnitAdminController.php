<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Catalog\Application\MeasurementUnitService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Sale units: built-in plus merchant-defined ones. */
final class MeasurementUnitAdminController extends AbstractController
{
    public function __construct(private readonly MeasurementUnitService $units, private readonly AdminContextResolver $contexts, private readonly Connection $db)
    {
    }

    #[Route('/admin/catalog/units', name: 'admin_catalog_units', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $locales = $this->db->fetchFirstColumn('SELECT locale_code FROM mc_store_locale WHERE store_id=? AND enabled=1 ORDER BY locale_code', [$ctx->storeId]);

        return $this->render('@storefront/admin/catalog/units.html.twig', [
            'units' => $this->units->all($request->getLocale(), false),
            'locales' => array_map('strval', $locales),
            'dimensions' => MeasurementUnitService::DIMENSIONS,
        ]);
    }

    #[Route('/admin/catalog/units/create', name: 'admin_catalog_units_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        $this->guard($request);
        $r = $request->request;
        $names = [];
        $shorts = $r->all('short');
        foreach ($r->all('name') as $locale => $name) {
            $names[(string) $locale] = ['name' => (string) $name, 'short' => (string) ($shorts[$locale] ?? '')];
        }
        try {
            $this->units->create((string) $r->get('code', ''), (string) $r->get('dimension', 'count'), (int) $r->get('decimal_scale', 0), $names);
            $this->addFlash('success', CanonicalUiText::get('admin.units.saved'));
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_catalog_units');
    }

    #[Route('/admin/catalog/units/{code}/toggle', name: 'admin_catalog_units_toggle', methods: ['POST'], requirements: ['code' => '[a-z][a-z0-9_]{0,31}'])]
    public function toggle(string $code, Request $request): RedirectResponse
    {
        $this->guard($request);
        try {
            $this->units->setEnabled($code, $request->request->get('enabled') === '1');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_catalog_units');
    }

    #[Route('/admin/catalog/units/{code}/delete', name: 'admin_catalog_units_delete', methods: ['POST'], requirements: ['code' => '[a-z][a-z0-9_]{0,31}'])]
    public function delete(string $code, Request $request): RedirectResponse
    {
        $this->guard($request);
        try {
            $this->units->delete($code);
            $this->addFlash('success', CanonicalUiText::get('admin.units.deleted'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_catalog_units');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_units', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
