<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Http;

use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Customer\Application\CustomerRegistrationService;
use Commerce\Modules\Customer\Application\CustomerAccountSecurityService;
use Commerce\Modules\Customer\Application\CustomerVerificationCodeService;
use Commerce\Modules\Security\Bot\TurnstileVerifier;
use Commerce\Modules\Customer\Application\DbalCustomerAccountQuery;
use Commerce\Modules\Customer\Application\CustomerProfileService;
use Commerce\Modules\Customer\Application\CustomerWishlistService;
use Commerce\Modules\Privacy\Application\CustomerPrivacyService;
use Commerce\Modules\Return\Application\ReturnRequestService;
use Commerce\Modules\DigitalProduct\Application\DigitalDownloadService;
use Commerce\Modules\OrderDocument\Application\OrderDocumentService;
use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class CustomerAccountController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly SiteCapabilitySettings $capabilities,
        private readonly CustomerRegistrationService $registration,
        private readonly CustomerAccountSecurityService $accountSecurity,
        private readonly CustomerVerificationCodeService $verificationCodes,
        private readonly TurnstileVerifier $turnstile,
        private readonly string $turnstileSiteKey,
        private readonly DbalCustomerAccountQuery $accounts,
        private readonly CustomerProfileService $profiles,
        private readonly CustomerWishlistService $wishlist,
        private readonly CustomerPrivacyService $privacy,
        private readonly ReturnRequestService $returns,
        private readonly DigitalDownloadService $downloads,
        private readonly OrderDocumentService $documents,
    ) {
    }

    #[Route('/account/login', name: 'customer_login', methods: ['GET', 'POST'], priority: 120)]
    public function login(Request $request, AuthenticationUtils $authentication): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) {
            throw $this->createNotFoundException();
        }
        if ($this->getUser() instanceof CustomerUser) {
            return $this->redirectToRoute('customer_account');
        }
        return $this->render('@storefront/account/login.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.vkhid'),
            'store_name' => $context->storeName,
            'last_username' => $authentication->getLastUsername(),
            'login_error' => $authentication->getLastAuthenticationError(),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account/register', name: 'customer_register', methods: ['GET', 'POST'], priority: 120)]
    public function register(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) {
            throw $this->createNotFoundException();
        }
        if ($this->getUser() instanceof CustomerUser) {
            return $this->redirectToRoute('customer_account');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('customer_register', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
            }
            if (!$this->turnstile->verify((string) $request->request->get('cf-turnstile-response', ''), $request->getClientIp())) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('flash.antibot_failed'));
                return $this->render('@storefront/account/register.html.twig', [
                    'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.stvoryty_oblikovyi_zapys'),
                    'store_name' => $context->storeName,
                    'turnstile_enabled' => $this->turnstile->enabled(),
                    'turnstile_site_key' => $this->turnstileSiteKey,
                    'seo_head' => ['robots' => 'noindex,nofollow'],
                ]);
            }
            try {
                $created = $this->registration->register(
                    (string) $request->request->get('email', ''),
                    (string) $request->request->get('display_name', ''),
                    (string) $request->request->get('password', ''),
                    $context->locale,
                );
                try {
                    $this->verificationCodes->requestCode((int) $created['id'], 'email', $context->storeName);
                } catch (\Throwable) {
                    try {
                        $this->accountSecurity->queueVerificationForCustomer((int) $created['id'], $context->storeName);
                    } catch (\Throwable) {
                        // Registration remains successful even if verification delivery is temporarily unavailable.
                    }
                }
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.oblikovyi_zapys_stvoreno_teper_uviidit_yakshcho_emai'));
                return $this->redirectToRoute('customer_login');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (\Throwable) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.ne_vdalosia_stvoryty_oblikovyi_zapys_mahazyn_prodovz'));
            }
        }

        return $this->render('@storefront/account/register.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.stvoryty_oblikovyi_zapys'),
            'store_name' => $context->storeName,
            'turnstile_enabled' => $this->turnstile->enabled(),
            'turnstile_site_key' => $this->turnstileSiteKey,
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account', name: 'customer_account', methods: ['GET'], priority: 120)]
    public function account(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) { throw $this->createNotFoundException(); }
        $user = $this->requireCustomer();
        return $this->render('@storefront/account/index.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.mii_kabinet'),
            'store_name' => $context->storeName,
            'customer' => $this->accounts->profile($user->id()),
            'orders' => $this->accounts->orders($user->id(), $context->storeId),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account/orders/{order}', name: 'customer_account_order', methods: ['GET'], priority: 120)]
    public function order(Request $request, string $order): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) { throw $this->createNotFoundException(); }
        $user = $this->requireCustomer();
        $data = $this->accounts->order($user->id(), $context->storeId, $order);
        if ($data === null) {
            throw $this->createNotFoundException();
        }
        return $this->render('@storefront/account/order.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.zamovlennia').$data['order_number'],
            'store_name' => $context->storeName,
            'order' => $data,
            'return_requests' => $this->returns->forOrder($user->id(), $context->storeId, $order),
            'return_reasons' => ReturnRequestService::REASONS,
            'downloads' => $this->downloads->forOrder($user->id(), $context->storeId, $order),
            'documents' => array_values(array_filter($this->documents->listForOrder($context->storeId, $order), static fn(array $d): bool => in_array($d['document_type'], ['invoice','credit_note'], true))),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account/downloads/{entitlement}', name: 'customer_digital_download', methods: ['GET'], priority: 125, requirements: ['entitlement' => '[0-9a-fA-F-]{36}'])]
    public function digitalDownload(Request $request, string $entitlement): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) throw $this->createNotFoundException();
        $user = $this->requireCustomer();
        try {
            $file = $this->downloads->claim($user->id(), $context->storeId, $entitlement);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('customer_account');
        }
        $response = new BinaryFileResponse($file['path']);
        $response->headers->set('Content-Type', $file['mime']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['filename']));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }

    #[Route('/account/wishlist', name: 'customer_wishlist', methods: ['GET'], priority: 120)]
    public function wishlist(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) { throw $this->createNotFoundException(); }
        $user = $this->requireCustomer();
        return $this->render('@storefront/account/wishlist.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.spysok_bazhan'),
            'store_name' => $context->storeName,
            'products' => $this->wishlist->products($user->id(), $context),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account/wishlist/{product}/add', name: 'customer_wishlist_add', methods: ['POST'], priority: 120)]
    public function addWishlist(Request $request, string $product): Response
    {
        if (!$this->isCsrfTokenValid('wishlist_add_'.$product, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        try { $this->wishlist->add($user->id(), $context, $product); $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.tovar_dodano_do_spysku_bazhan')); }
        catch (\DomainException $e) { $this->addFlash('error', $e->getMessage()); }
        catch (\Throwable) { $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.ne_vdalosia_zminyty_spysok_bazhan_mahazyn_prodovzhui')); }
        return $this->redirectToRoute('customer_wishlist');
    }

    #[Route('/account/wishlist/{product}/remove', name: 'customer_wishlist_remove', methods: ['POST'], priority: 120)]
    public function removeWishlist(Request $request, string $product): Response
    {
        if (!$this->isCsrfTokenValid('wishlist_remove_'.$product, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        try { $this->wishlist->remove($user->id(), $context->storeId, $product); } catch (\Throwable) { $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.ne_vdalosia_zminyty_spysok_bazhan')); }
        return $this->redirectToRoute('customer_wishlist');
    }

    #[Route('/account/profile', name: 'customer_account_profile', methods: ['POST'], priority: 120)]
    public function updateProfile(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('customer_profile', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        try {
            $this->profiles->update($user->id(), (string) $request->request->get('display_name', ''), (string) $request->request->get('phone', ''), (string) $request->request->get('locale', $context->locale));
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.profil_onovleno'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.ne_vdalosia_onovyty_profil_potochni_dani_ne_zmineno'));
        }
        return $this->redirectToRoute('customer_account');
    }

    #[Route('/account/privacy/export', name: 'customer_privacy_export', methods: ['POST'], priority: 120)]
    public function exportPrivacy(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('customer_privacy_export', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        $payload = $this->privacy->export($user->id(), $context->storeId);
        $response = new Response((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'personal-data.json'));
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }

    #[Route('/account/privacy/erasure', name: 'customer_privacy_erasure', methods: ['POST'], priority: 120)]
    public function requestErasure(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('customer_privacy_erasure', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        try {
            $requestId = $this->privacy->requestErasure($user->id(), $context->storeId);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.zapyt_na_vydalennia_danykh_pryiniato').$requestId.\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.dani_zamovlen_iaki_neobkhidno_zberihaty_za_zakonom_n'));
        } catch (\Throwable) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customeraccountcontroller.ne_vdalosia_stvoryty_zapyt_oblikovyi_zapys_i_mahazyn'));
        }
        return $this->redirectToRoute('customer_account');
    }

    #[Route('/account/logout', name: 'customer_logout', methods: ['POST'], priority: 120)]
    public function logout(): never
    {
        throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f9d1175255b3'));
    }

    private function requireCustomer(): CustomerUser
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }
}
