<?php

declare(strict_types=1);

namespace Commerce\Modules\Identity\Http;

use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Customer\Security\DbalCustomerUserProvider;
use Commerce\Modules\Identity\Application\ExternalIdentityLoginService;
use Commerce\Modules\Identity\Google\GoogleIdentityProvider;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleLoginController extends AbstractController
{
    public function __construct(
        private readonly GoogleIdentityProvider $google,
        private readonly ExternalIdentityLoginService $logins,
        private readonly DbalCustomerUserProvider $users,
        private readonly StorefrontContextResolver $contexts,
        private readonly SiteCapabilitySettings $capabilities,
        private readonly Security $security,
        private readonly string $publicBaseUrl,
    ) {}

    #[Route('/account/login/google', name: 'customer_login_google', methods: ['GET'], priority: 125)]
    public function start(Request $request): RedirectResponse
    {
        $context = $this->contexts->resolve($request);
        if (!$this->google->enabled() || !$this->capabilities->enabled($context->storeId, 'customer_accounts')) {
            throw $this->createNotFoundException();
        }
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $request->getSession()->set('google_oauth', ['state' => $state, 'nonce' => $nonce, 'at' => time()]);
        return new RedirectResponse($this->google->authorizationUrl($this->redirectUri(), $state, $nonce));
    }

    #[Route('/account/login/google/callback', name: 'customer_login_google_callback', methods: ['GET'], priority: 125)]
    public function callback(Request $request): RedirectResponse
    {
        $context = $this->contexts->resolve($request);
        if (!$this->google->enabled() || !$this->capabilities->enabled($context->storeId, 'customer_accounts')) {
            throw $this->createNotFoundException();
        }
        $session = $request->getSession();
        $saved = $session->get('google_oauth');
        $session->remove('google_oauth');
        $state = (string)$request->query->get('state', '');
        if (!is_array($saved) || $state === '' || !hash_equals((string)($saved['state'] ?? ''), $state) || time() - (int)($saved['at'] ?? 0) > 600) {
            return $this->fail('flash.google_login_failed');
        }
        $code = (string)$request->query->get('code', '');
        if ($code === '' || $request->query->has('error')) {
            return $this->fail('flash.google_login_failed');
        }
        try {
            $identity = $this->google->exchange($code, $this->redirectUri(), (string)$saved['nonce']);
            $email = $this->logins->resolve($context->storeId, $identity, $request->getLocale());
            $user = $this->users->loadUserByIdentifier($email);
            $request->attributes->set('_device_skip', true); // Google sign-in is already a strong, separately verified factor.
            $this->security->login($user, 'security.authenticator.form_login.customer', 'customer');
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage() === 'identity_local_unverified' ? 'flash.google_login_verify_first' : 'flash.google_login_failed');
        } catch (\Throwable) {
            return $this->fail('flash.google_login_failed');
        }
        return $this->redirectToRoute('customer_account');
    }

    private function redirectUri(): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/account/login/google/callback';
    }

    private function fail(string $flashKey): RedirectResponse
    {
        $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get($flashKey));
        return $this->redirectToRoute('customer_login');
    }
}
