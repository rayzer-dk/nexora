<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Http;

use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Customer\Application\CustomerAccountSecurityService;
use Commerce\Modules\Customer\Application\CustomerVerificationCodeService;
use Commerce\Modules\Customer\Application\DbalCustomerAccountQuery;
use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class CustomerSecurityController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly SiteCapabilitySettings $capabilities,
        private readonly CustomerAccountSecurityService $security,
        private readonly CustomerVerificationCodeService $verificationCodes,
        private readonly DbalCustomerAccountQuery $accounts,
        private readonly \Commerce\Modules\Security\Captcha\CaptchaVerifier $captcha,
    ) {
    }

    #[Route('/account/password/forgot', name: 'customer_password_forgot', methods: ['GET', 'POST'], priority: 130)]
    public function forgot(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('customer_password_forgot', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
            }
            if (!$this->captcha->verify($request, 'password_recovery')) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('flash.antibot_failed'));
                return $this->redirectToRoute('customer_password_forgot');
            }
            try {
                $this->security->requestPasswordReset((string) $request->request->get('email', ''), $context->storeName);
            } catch (Throwable) {
                // Deliberately keep the public response identical. A notification/backend
                // outage must not expose account existence and must not break storefront UX.
            }
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.yakshcho_oblikovyi_zapys_z_takym_email_isnuie_instru'));
            return $this->redirectToRoute('customer_password_forgot');
        }

        return $this->render('@storefront/account/password_forgot.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.vidnovlennia_parolia'),
            'store_name' => $context->storeName,
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account/password/reset/{token}', name: 'customer_password_reset', methods: ['GET', 'POST'], priority: 130, requirements: ['token' => '[A-Za-z0-9_-]{32,128}'])]
    public function reset(Request $request, string $token): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) {
            throw $this->createNotFoundException();
        }

        $usable = $this->security->tokenIsUsable($token, CustomerAccountSecurityService::resetPurpose());
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('customer_password_reset_' . hash('sha256', $token), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
            }
            if (!$usable) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.posylannia_nediisne_abo_strok_ioho_dii_zavershyvsia'));
                return $this->redirectToRoute('customer_password_forgot');
            }

            $password = (string) $request->request->get('password', '');
            $confirm = (string) $request->request->get('password_confirm', '');
            if (!hash_equals($password, $confirm)) {
                $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.paroli_ne_zbihaiutsia'));
            } else {
                try {
                    if ($this->security->resetPassword($token, $password)) {
                        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.parol_zmineno_teper_mozhna_uviity_z_novym_parolem'));
                        return $this->redirectToRoute('customer_login');
                    }
                    $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.posylannia_nediisne_abo_vzhe_vykorystane'));
                } catch (\DomainException $e) {
                    $this->addFlash('error', $e->getMessage());
                } catch (Throwable) {
                    $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.parol_ne_zmineno_potochnyi_parol_zalyshyvsia_chynnym'));
                }
            }
        }

        return $this->render('@storefront/account/password_reset.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.novyi_parol'),
            'store_name' => $context->storeName,
            'token' => $token,
            'usable' => $usable,
            'csrf_id' => 'customer_password_reset_' . hash('sha256', $token),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account/verify/{token}', name: 'customer_email_verify', methods: ['GET'], priority: 130, requirements: ['token' => '[A-Za-z0-9_-]{32,128}'])]
    public function verifyEmail(Request $request, string $token): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, 'customer_accounts')) {
            throw $this->createNotFoundException();
        }
        try {
            $verified = $this->security->verifyEmail($token);
        } catch (Throwable) {
            $verified = false;
        }

        return $this->render('@storefront/account/email_verified.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get($verified ? 'email_verified.title' : 'email_verified.failed_title'),
            'store_name' => $context->storeName,
            'verified' => $verified,
            'signed_in' => $this->getUser() instanceof CustomerUser,
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account/verification', name: 'customer_verification', methods: ['GET'], priority: 135)]
    public function verification(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }
        return $this->render('@storefront/account/verification.html.twig', [
            'page_title' => 'Account verification',
            'store_name' => $context->storeName,
            'customer' => $this->accounts->profile($user->id()),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/account/verification/request', name: 'customer_verification_request', methods: ['POST'], priority: 135)]
    public function requestVerificationCode(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('customer_verification_request', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $channel = (string) $request->request->get('channel', 'email');
        try {
            $this->verificationCodes->requestCode($user->id(), $channel, $context->storeName);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('flash.verification_sent'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (Throwable) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('flash.verification_send_failed'));
        }
        return $this->redirectToRoute('customer_verification');
    }

    #[Route('/account/verification/confirm', name: 'customer_verification_confirm', methods: ['POST'], priority: 135)]
    public function confirmVerificationCode(Request $request): Response
    {
        $this->contexts->resolve($request);
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('customer_verification_confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $channel = (string) $request->request->get('channel', 'email');
        $code = (string) $request->request->get('code', '');
        if ($this->verificationCodes->verify($user->id(), $channel, $code)) {
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('flash.contact_verified'));
        } else {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('flash.verification_invalid'));
        }
        return $this->redirectToRoute('customer_verification');
    }

    #[Route('/account/email/resend-verification', name: 'customer_email_resend_verification', methods: ['POST'], priority: 130)]
    public function resendVerification(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('customer_resend_verification', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        }

        try {
            $this->security->queueVerificationForCustomer($user->id(), $context->storeName);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.yakshcho_email_shche_ne_pidtverdzheno_nove_posylanni'));
        } catch (Throwable) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.ne_vdalosia_postavyty_povidomlennia_v_cherhu_oblikov'));
        }
        return $this->redirectToRoute('customer_account');
    }
}
