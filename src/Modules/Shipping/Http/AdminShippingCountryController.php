<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Shipping\Application\ShippingCountryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminShippingCountryController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly ShippingCountryService $countries)
    {
    }

    #[Route('/admin/shipments/countries', name: 'admin_shipment_countries', methods: ['GET'], priority: 10)]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $configured = $this->countries->configured($ctx->storeId);
        $list = [];
        foreach (ShippingCountryService::allCountryCodes() as $code) {
            $list[] = ['code' => $code, 'name' => ShippingCountryService::countryName($code, $ctx->locale), 'enabled' => $configured[$code] ?? false];
        }
        usort($list, static fn (array $a, array $b): int => [!$a['enabled'], $a['name']] <=> [!$b['enabled'], $b['name']]);
        $selected = strtoupper((string) $request->query->get('country', array_key_first($configured) ?? ''));

        return $this->render('@storefront/admin/shipping/countries.html.twig', [
            'countries' => $list, 'restricted' => $configured !== [], 'selected' => $selected,
            'regions' => $selected !== '' ? $this->countries->regions($ctx->storeId, $selected) : [],
            'selected_name' => $selected !== '' ? ShippingCountryService::countryName($selected, $ctx->locale) : '',
            'enabled_countries' => array_values(array_filter($list, static fn (array $c): bool => $c['enabled'])),
            'can_import' => in_array($selected, ['UA', 'PL', 'DK'], true),
        ]);
    }

    #[Route('/admin/shipments/countries/save', name: 'admin_shipment_countries_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->guard($request);
        $ctx = $this->contexts->resolve($request);
        try {
            $this->countries->saveCountries($ctx->storeId, array_map('strval', (array) $request->request->all('countries')));
            $this->addFlash('success', CanonicalUiText::get('admin.shipping_countries.saved'));
        } catch (\DomainException) {
            $this->addFlash('error', CanonicalUiText::get('admin.shipping_countries.empty'));
        }

        return $this->redirectToRoute('admin_shipment_countries');
    }

    #[Route('/admin/shipments/countries/regions', name: 'admin_shipment_regions_save', methods: ['POST'])]
    public function regions(Request $request): Response
    {
        $this->guard($request);
        $ctx = $this->contexts->resolve($request);
        $country = strtoupper((string) $request->request->get('country', ''));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw $this->createNotFoundException();
        }
        $action = (string) $request->request->get('action', 'save');
        try {
            if ($action === 'import') {
                $this->countries->importDefaultRegions($ctx->storeId, $country);
            } elseif ($action === 'add') {
                $this->countries->addRegion($ctx->storeId, $country, (string) $request->request->get('name', ''));
            } else {
                $this->countries->saveRegionStates($ctx->storeId, $country, array_map('intval', (array) $request->request->all('enabled')));
            }
            $this->addFlash('success', CanonicalUiText::get('admin.shipping_countries.saved'));
        } catch (\DomainException) {
            $this->addFlash('error', CanonicalUiText::get('admin.shipping_countries.region_invalid'));
        }

        return $this->redirectToRoute('admin_shipment_countries', ['country' => $country]);
    }

    #[Route('/admin/shipments/countries/regions/{id}/delete', name: 'admin_shipment_region_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteRegion(int $id, Request $request): Response
    {
        $this->guard($request);
        $ctx = $this->contexts->resolve($request);
        $this->countries->deleteRegion($ctx->storeId, $id);

        return $this->redirectToRoute('admin_shipment_countries', ['country' => strtoupper((string) $request->request->get('country', ''))]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('shipping_countries', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
