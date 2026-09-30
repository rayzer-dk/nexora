<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Store\StoreLocalizationSettings;
use Commerce\Modules\Pricing\Application\CurrencyPriceSynchronizer;
use Commerce\Modules\Pricing\Application\ExchangeRateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class LocalizationAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly StoreLocalizationSettings $settings,
        private readonly ExchangeRateService $rates,
        private readonly CurrencyPriceSynchronizer $prices,
    ) {
    }

    #[Route('/admin/system/localization', name: 'admin_system_localization', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);

        return $this->render('@storefront/admin/system/localization.html.twig', ['data' => $this->settings->overview($context->storeId)]);
    }

    #[Route('/admin/system/localization/locales', name: 'admin_system_localization_locales', methods: ['POST'])]
    public function locales(Request $request): Response
    {
        return $this->guarded($request, 'localization_locales', function (int $storeId) use ($request): string {
            $rows = $request->request->all('locale');
            $code = trim((string) $request->request->get('new_code', ''));
            if ($code !== '') {
                $code = $this->settings->addLocale($code, (string) $request->request->get('new_name', ''), (string) $request->request->get('new_native_name', ''));
                $rows[$code] = ['enabled' => '1', 'sort_order' => '100'];
            }
            $this->settings->saveLocales($storeId, $rows);

            return CanonicalUiText::get('admin.localization.locales.saved');
        });
    }

    #[Route('/admin/system/localization/currencies', name: 'admin_system_localization_currencies', methods: ['POST'])]
    public function currencies(Request $request): Response
    {
        return $this->guarded($request, 'localization_currencies', function (int $storeId) use ($request): string {
            $rows = $request->request->all('currency');
            $code = trim((string) $request->request->get('new_code', ''));
            if ($code !== '') {
                $code = $this->settings->addCurrency($code, (string) $request->request->get('new_name', ''), (string) $request->request->get('new_symbol', ''), $request->request->getInt('new_minor_units', 2));
                $rows[$code] = ['enabled' => '0', 'auto_convert' => '0', 'rate_source' => 'nbu', 'rounding_increment_minor' => '1', 'sort_order' => '100'];
            }
            $this->settings->saveCurrencies($storeId, $rows);

            return $this->syncMessage($storeId, CanonicalUiText::get('admin.localization.currencies.saved'));
        });
    }

    #[Route('/admin/system/localization/rate', name: 'admin_system_localization_rate', methods: ['POST'])]
    public function rate(Request $request): Response
    {
        return $this->guarded($request, 'localization_rate', function (int $storeId) use ($request): string {
            $base = strtoupper((string) $request->request->get('base'));
            $quote = strtoupper((string) $request->request->get('quote'));
            // The form asks "how many <base> for 1 <quote>" (e.g. 45.20 UAH for 1 EUR), stored as the quote->base pair.
            $this->rates->storeManual($quote, $base, (string) $request->request->get('value'), null);

            return $this->syncMessage($storeId, CanonicalUiText::get('admin.localization.rate.saved'));
        });
    }

    #[Route('/admin/system/localization/refresh', name: 'admin_system_localization_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        return $this->guarded($request, 'localization_refresh', function (int $storeId): string {
            $message = '';
            if ($this->rates->requiredPairs() !== []) {
                try {
                    $result = $this->rates->refresh();
                    $message = CanonicalUiText::get('admin.localization.rates.fetched', ['date' => $result['date'], 'count' => (string) $result['stored']]) . ' ';
                    if ($result['missing'] !== []) {
                        $message .= CanonicalUiText::get('admin.localization.rates.missing', ['pairs' => implode(', ', $result['missing'])]) . ' ';
                    }
                } catch (Throwable) {
                    $this->addFlash('warning', CanonicalUiText::get('admin.localization.rates.fetch_failed'));
                }
            }

            return $this->syncMessage($storeId, $message, true);
        });
    }

    /** @param callable(int):string $action */
    private function guarded(Request $request, string $csrfId, callable $action): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($csrfId, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', CanonicalUiText::get('admin.localization.csrf'));

            return $this->redirectToRoute('admin_system_localization');
        }
        try {
            $this->addFlash('success', $action($this->contexts->resolve($request)->storeId));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (Throwable) {
            $this->addFlash('error', CanonicalUiText::get('common.error.operation_failed'));
        }

        return $this->redirectToRoute('admin_system_localization');
    }

    private function syncMessage(int $storeId, string $prefix, bool $full = false): string
    {
        $written = 0;
        $removed = 0;
        $noRate = [];
        foreach ($this->prices->sync($storeId, $full) as $row) {
            $written += $row['written'];
            $removed += $row['removed'];
            if ($row['mode'] === 'no_rate') {
                $noRate[] = $row['currency'];
            }
        }
        $message = trim($prefix . ' ' . CanonicalUiText::get('admin.localization.prices.synced', ['written' => (string) $written, 'removed' => (string) $removed]));
        if ($noRate !== []) {
            $this->addFlash('warning', CanonicalUiText::get('admin.localization.prices.no_rate', ['currencies' => implode(', ', $noRate)]));
        }

        return $message;
    }
}
