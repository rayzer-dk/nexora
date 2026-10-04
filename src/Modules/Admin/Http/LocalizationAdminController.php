<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\I18n\LanguagePackService;
use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Core\Store\StoreLocalizationSettings;
use Commerce\Modules\Pricing\Application\CurrencyPriceSynchronizer;
use Commerce\Modules\Pricing\Application\ExchangeRateService;
use Commerce\Modules\Pricing\Infrastructure\ApiKeyExchangeRateSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly SystemSettingStore $systemSettings,
        private readonly ApiKeyExchangeRateSource $apiSource,
        private readonly LanguagePackService $packs,
    ) {
    }

    #[Route('/admin/system/localization', name: 'admin_system_localization', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);

        $api = $this->apiSource->config();

        return $this->render('@storefront/admin/system/localization.html.twig', [
            'data' => $this->settings->overview($context->storeId),
            'rate_sources' => $this->rates->sourceChoices(),
            'rate_providers' => $this->rates->providerStatus(),
            'rate_api' => ['service' => $api['service'], 'has_key' => $api['key'] !== '', 'services' => ApiKeyExchangeRateSource::SERVICES],
        ]);
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

    /** The texts of one language as JSON to translate: the current text, or the Ukrainian one where there is no translation yet. */
    #[Route('/admin/system/localization/pack/{locale}', name: 'admin_system_localization_pack_download', methods: ['GET'], requirements: ['locale' => '[a-z]{2,3}(?:-[A-Z]{2})?'])]
    public function downloadPack(string $locale): Response
    {
        $response = new JsonResponse($this->packs->export($locale), 200, [], false);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'storefront-' . $locale . '.json'));

        return $response;
    }

    /** Saves a translated JSON file as the language pack of a language (var/translations, kept across updates). */
    #[Route('/admin/system/localization/pack', name: 'admin_system_localization_pack_upload', methods: ['POST'])]
    public function uploadPack(Request $request): Response
    {
        return $this->guarded($request, 'localization_pack', function () use ($request): string {
            $locale = trim((string) $request->request->get('pack_locale', ''));
            $file = $request->files->get('pack_file');
            $json = $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->isValid() ? (string) file_get_contents($file->getPathname()) : '';
            if ($json === '') {
                throw new \DomainException(CanonicalUiText::get('admin.langpack.error_nofile'));
            }
            $result = $this->packs->import($locale, $json);
            $message = CanonicalUiText::get('admin.langpack.saved', ['locale' => $locale, 'saved' => $result['saved'], 'translated' => $result['translated']]);
            if ($result['rejected'] !== []) {
                $message .= ' ' . CanonicalUiText::get('admin.langpack.rejected', ['count' => count($result['rejected']), 'keys' => implode(', ', array_slice(array_keys($result['rejected']), 0, 5))]);
            }

            return $message;
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
                $rows[$code] = ['enabled' => '0', 'auto_convert' => '0', 'rate_source' => 'auto', 'rounding_increment_minor' => '1', 'sort_order' => '100'];
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

    #[Route('/admin/system/localization/rate-api', name: 'admin_system_localization_rate_api', methods: ['POST'])]
    public function rateApi(Request $request): Response
    {
        return $this->guarded($request, 'localization_rate_api', function (int $storeId) use ($request): string {
            $service = (string) $request->request->get('service', '');
            if (!in_array($service, ApiKeyExchangeRateSource::SERVICES, true)) {
                throw new \DomainException(CanonicalUiText::get('admin.localization.rate_api.invalid_service'));
            }
            $current = $this->apiSource->config();
            $key = trim((string) $request->request->get('api_key', ''));
            if ($request->request->getBoolean('clear_key')) {
                $key = '';
            } elseif ($key === '') {
                $key = $current['key'];
            } elseif (preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $key) !== 1) {
                throw new \DomainException(CanonicalUiText::get('admin.localization.rate_api.invalid_key'));
            }
            $this->systemSettings->setArray(ApiKeyExchangeRateSource::SETTING_KEY, ['service' => $service, 'key' => $key]);

            return CanonicalUiText::get('admin.localization.rate_api.saved');
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
