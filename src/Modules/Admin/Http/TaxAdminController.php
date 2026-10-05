<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Tax\Application\TaxSettingsService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** VAT: rates per country and tax class, and how prices are shown to shoppers. */
final class TaxAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly TaxSettingsService $tax, private readonly Connection $db)
    {
    }

    #[Route('/admin/system/tax', name: 'admin_system_tax', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;

        return $this->render('@storefront/admin/system/tax.html.twig', [
            'classes' => $this->tax->classes(),
            'rates' => $this->tax->rates(),
            'modes' => TaxSettingsService::DISPLAY_MODES,
            'mode' => $this->tax->displayMode($storeId),
            'country' => (string) $this->db->fetchOne('SELECT default_country FROM mc_store WHERE id=?', [$storeId]),
            'presets' => array_keys(TaxSettingsService::STANDARD_RATES),
        ]);
    }

    #[Route('/admin/system/tax/mode', name: 'admin_system_tax_mode', methods: ['POST'])]
    public function mode(Request $request): RedirectResponse
    {
        return $this->run($request, function () use ($request): void {
            $this->tax->setDisplayMode($this->contexts->resolve($request)->storeId, (string) $request->request->get('mode', ''));
        });
    }

    #[Route('/admin/system/tax/rates/add', name: 'admin_system_tax_rate_add', methods: ['POST'])]
    public function add(Request $request): RedirectResponse
    {
        return $this->run($request, function () use ($request): void {
            $this->tax->addRate((int) $request->request->get('class_id', 0), (string) $request->request->get('country', ''), (string) $request->request->get('name', ''), (string) $request->request->get('percent', ''));
        });
    }

    #[Route('/admin/system/tax/rates/standard', name: 'admin_system_tax_rate_standard', methods: ['POST'])]
    public function standard(Request $request): RedirectResponse
    {
        return $this->run($request, function () use ($request): void {
            $this->tax->addStandardRate((string) $request->request->get('country', ''));
        });
    }

    #[Route('/admin/system/tax/rates/{id}/edit', name: 'admin_system_tax_rate_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $this->contexts->resolve($request);
        $rate = $this->tax->rate($id);
        if ($rate === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@storefront/admin/system/tax_edit.html.twig', [
            'rate' => $rate,
            'classes' => $this->tax->classes(),
            'percent' => rtrim(rtrim(number_format(((int) $rate['rate_bps']) / 100, 2, '.', ''), '0'), '.'),
        ]);
    }

    #[Route('/admin/system/tax/rates/{id}/edit', name: 'admin_system_tax_rate_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_tax', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $in = $request->request;
        try {
            $this->tax->updateRate($id, (int) $in->get('class_id', 0), (string) $in->get('country', ''), (string) $in->get('region', ''), (string) $in->get('name', ''), (string) $in->get('percent', ''), (int) $in->get('priority', 100), $in->getBoolean('enabled'), (string) $in->get('valid_from', ''), (string) $in->get('valid_to', ''));
            $this->addFlash('success', CanonicalUiText::get('admin.tax.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.tax.error.' . $e->getMessage()));

            return $this->redirectToRoute('admin_system_tax_rate_edit', ['id' => $id]);
        }

        return $this->redirectToRoute('admin_system_tax');
    }

    #[Route('/admin/system/tax/rates/{id}/toggle', name: 'admin_system_tax_rate_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(Request $request, int $id): RedirectResponse
    {
        return $this->run($request, function () use ($id): void {
            $this->tax->toggleRate($id);
        });
    }

    #[Route('/admin/system/tax/rates/{id}/delete', name: 'admin_system_tax_rate_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): RedirectResponse
    {
        return $this->run($request, function () use ($id): void {
            $this->tax->deleteRate($id);
        });
    }

    private function run(Request $request, callable $action): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_tax', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $action();
            $this->addFlash('success', CanonicalUiText::get('admin.tax.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.tax.error.' . $e->getMessage()));
        }

        return $this->redirectToRoute('admin_system_tax');
    }
}
