<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Core\Store\StoreIdentitySettings;
use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class StoreSettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly StoreIdentitySettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/system/store', name: 'admin_system_store', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('store_identity', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.storesettingsadmincontroller.sesiia_formy_zavershylas_dani_mahazynu_ne_zmineno'));
                return $this->redirectToRoute('admin_system_store');
            }
            try {
                $this->settings->save($context->storeId, [
                    'name' => $request->request->get('name'),
                    'default_locale' => $request->request->get('default_locale'),
                    'default_currency' => $request->request->get('default_currency'),
                    'timezone' => $request->request->get('timezone'),
                    'legal_name' => $request->request->get('legal_name'),
                    'registration_number' => $request->request->get('registration_number'),
                    'tax_number' => $request->request->get('tax_number'),
                    'vat_number' => $request->request->get('vat_number'),
                    'country_code' => $request->request->get('country_code'),
                    'registration_address' => $request->request->get('registration_address'),
                    'iban' => $request->request->get('iban'),
                    'bank_name' => $request->request->get('bank_name'),
                    'email' => $request->request->get('email'),
                    'phone' => $request->request->get('phone'),
                    'privacy_contact' => $request->request->get('privacy_contact'),
                    'return_contact' => $request->request->get('return_contact'),
                    'warranty_contact' => $request->request->get('warranty_contact'),
                    'domains' => $request->request->get('domains', ''),
                ], $this->actor());
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.storesettingsadmincontroller.dani_mahazynu_zberezheno_atomarno_poperednia_konfihu'));
            } catch (Throwable $e) {
                $this->logger->error('Store settings save failed: {exception_class}: {exception_message}', [
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                    'exception' => $e,
                    'store_id' => $context->storeId,
                ]);
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.zminy_ne_zastosovano') . $this->safeMessage($e));
            }
            return $this->redirectToRoute('admin_system_store');
        }

        $locales = [];
        $currencies = [];
        try {
            $locales = $connection->fetchAllAssociative('SELECT code,native_name,name FROM mc_locale WHERE enabled=1 ORDER BY native_name,code');
            $currencies = $connection->fetchAllAssociative('SELECT code,name,symbol FROM mc_currency WHERE enabled=1 ORDER BY code');
        } catch (Throwable) {
            // The form remains usable with the current values on partially migrated installations.
        }

        return $this->render('@storefront/admin/system/store.html.twig', [
            'platform_version' => PlatformVersion::VERSION,
            'settings' => $this->settings->get($context->storeId),
            'revisions' => $this->settings->history($context->storeId, 12),
            'locales' => $locales,
            'currencies' => $currencies,
            'timezones' => ['Europe/Kyiv', 'Europe/Copenhagen', 'Europe/Warsaw', 'Europe/Berlin', 'UTC'],
        ]);
    }

    #[Route('/admin/system/store/rollback/{revisionId}', name: 'admin_system_store_rollback', methods: ['POST'], requirements: ['revisionId' => '\\d+'])]
    public function rollback(Request $request, int $revisionId): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('store_identity_rollback', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.systemadmincontroller.nediisnyi_token_bezpeky_vidkat_ne_vykonano'));
            return $this->redirectToRoute('admin_system_store');
        }
        try {
            $this->settings->rollback($context->storeId, $revisionId, $this->actor());
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.storesettingsadmincontroller.dani_mahazynu_vidnovleno_z_vybranoi_revizii_iak_novu'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.appearanceadmincontroller.vidkat_ne_vykonano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_system_store');
    }

    private function actor(): string
    {
        $user = $this->getUser();
        return $user instanceof AdminUser ? 'admin:' . $user->id : 'admin';
    }

    private function safeMessage(Throwable $e): string
    {
        $message = \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed');
        return mb_substr($message, 0, 400, 'UTF-8');
    }
}
