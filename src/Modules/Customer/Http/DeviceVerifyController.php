<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Customer\Device\DeviceTrustService;
use Commerce\Modules\Customer\Device\DeviceTrustSubscriber;
use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeviceVerifyController extends AbstractController
{
    public function __construct(
        private readonly DeviceTrustService $devices,
        private readonly StorefrontContextResolver $contexts,
    ) {
    }

    #[Route('/account/device-verify', name: 'customer_device_verify', methods: ['GET', 'POST'], priority: 130)]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            return $this->redirectToRoute('customer_login');
        }
        $session = $request->getSession();
        if ($session->get(DeviceTrustSubscriber::SESSION_KEY) !== true) {
            return $this->redirectToRoute('customer_account');
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('customer_device_verify', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            if ($request->request->get('action') === 'resend') {
                $this->devices->challenge($user->id(), $this->contexts->resolve($request)->storeName);
                $this->addFlash('success', CanonicalUiText::get('flash.device_code_sent'));

                return $this->redirectToRoute('customer_device_verify');
            }
            if ($this->devices->verify($user->id(), (string) $request->request->get('code', ''))) {
                $token = $this->devices->trust($user->id(), (string) $request->headers->get('User-Agent', ''));
                $session->remove(DeviceTrustSubscriber::SESSION_KEY);
                $response = $this->redirectToRoute('customer_account');
                $response->headers->setCookie(Cookie::create(DeviceTrustService::COOKIE, $token, time() + DeviceTrustService::TRUST_DAYS * 86400, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));

                return $response;
            }
            $this->addFlash('error', CanonicalUiText::get('flash.device_code_invalid'));

            return $this->redirectToRoute('customer_device_verify');
        }

        return $this->render('@storefront/account/device_verify.html.twig', [
            'page_title' => CanonicalUiText::get('device_verify_title'),
            'store_name' => $this->contexts->resolve($request)->storeName,
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }
}
