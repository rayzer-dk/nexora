<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Security\AdminMfaGateSubscriber;
use Commerce\Modules\Admin\Security\AdminMfaService;
use Commerce\Modules\Admin\Security\Totp;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminMfaController extends AbstractController
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly AdminMfaService $mfa, private readonly bool $required = false)
    {
    }

    #[Route('/admin/2fa', name: 'admin_mfa_challenge', methods: ['GET', 'POST'])]
    public function challenge(Request $request): Response
    {
        $user = $this->admin();
        $session = $request->getSession();
        if ($session->get(AdminMfaGateSubscriber::SESSION_OK) === $user->id || !$this->mfa->isEnabled($user->id)) {
            return $this->redirect($this->safeTarget($session->get(AdminMfaGateSubscriber::SESSION_TARGET)));
        }
        $error = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_mfa_challenge', (string) $request->request->get('_csrf_token'))) {
                $error = true;
            } elseif ($this->mfa->verifyLogin($user->id, (string) $request->request->get('code', ''))) {
                $session->remove('admin_mfa_attempts');
                $session->set(AdminMfaGateSubscriber::SESSION_OK, $user->id);
                $target = $session->get(AdminMfaGateSubscriber::SESSION_TARGET);
                $session->remove(AdminMfaGateSubscriber::SESSION_TARGET);
                return $this->redirect($this->safeTarget($target));
            } else {
                $error = true;
                $attempts = (int) $session->get('admin_mfa_attempts', 0) + 1;
                $session->set('admin_mfa_attempts', $attempts);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    // Too many wrong codes: drop the session so the password step (which is throttled) must be repeated.
                    $session->invalidate();
                    return new RedirectResponse('/admin/login', 303);
                }
            }
        }
        return $this->render('@storefront/admin/mfa_challenge.html.twig', ['error' => $error], new Response('', $error ? 401 : 200));
    }

    #[Route('/admin/account/security', name: 'admin_account_security', methods: ['GET'])]
    public function security(Request $request): Response
    {
        $user = $this->admin();
        $enabled = $this->mfa->isEnabled($user->id);
        $secret = null;
        $uri = null;
        if (!$enabled && ($this->required || $request->query->getBoolean('setup'))) {
            $secret = $this->mfa->pendingSecret($user->id);
            $uri = Totp::provisioningUri($secret, $user->getUserIdentifier(), 'Nexora');
        }
        $session = $request->getSession();
        $codes = $session->get('admin_mfa_new_codes');
        $session->remove('admin_mfa_new_codes');
        return $this->render('@storefront/admin/account_security.html.twig', [
            'enabled' => $enabled,
            'mfa_required' => $this->required,
            'secret' => $secret,
            'provisioning_uri' => $uri,
            'secret_groups' => $secret === null ? [] : str_split($secret, 4),
            'recovery_codes' => is_array($codes) ? $codes : [],
            'recovery_remaining' => $enabled ? $this->mfa->remainingRecoveryCodes($user->id) : 0,
        ]);
    }

    #[Route('/admin/account/security/confirm', name: 'admin_account_security_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $user = $this->admin();
        if (!$this->isCsrfTokenValid('admin_mfa_confirm', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->t('admin.mfa.flash.csrf'));
            return $this->redirectToRoute('admin_account_security');
        }
        $codes = $this->mfa->confirm($user->id, (string) $request->request->get('code', ''));
        if ($codes === null) {
            $this->addFlash('error', $this->t('admin.mfa.flash.wrong_code'));
            return $this->redirectToRoute('admin_account_security', ['setup' => 1]);
        }
        $session = $request->getSession();
        $session->set(AdminMfaGateSubscriber::SESSION_OK, $user->id);
        $session->set('admin_mfa_new_codes', $codes);
        $this->addFlash('success', $this->t('admin.mfa.flash.enabled'));
        return $this->redirectToRoute('admin_account_security');
    }

    #[Route('/admin/account/security/cancel', name: 'admin_account_security_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        if ($this->isCsrfTokenValid('admin_mfa_cancel', (string) $request->request->get('_csrf_token'))) {
            $this->mfa->cancelPending($this->admin()->id);
        }
        return $this->redirectToRoute('admin_account_security');
    }

    #[Route('/admin/account/security/recovery', name: 'admin_account_security_recovery', methods: ['POST'])]
    public function recovery(Request $request): Response
    {
        $user = $this->admin();
        if (!$this->isCsrfTokenValid('admin_mfa_recovery', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->t('admin.mfa.flash.csrf'));
            return $this->redirectToRoute('admin_account_security');
        }
        $codes = $this->mfa->regenerateRecoveryCodes($user->id, (string) $request->request->get('code', ''));
        if ($codes === null) {
            $this->addFlash('error', $this->t('admin.mfa.flash.wrong_code'));
        } else {
            $request->getSession()->set('admin_mfa_new_codes', $codes);
            $this->addFlash('success', $this->t('admin.mfa.flash.recovery_regenerated'));
        }
        return $this->redirectToRoute('admin_account_security');
    }

    #[Route('/admin/account/security/disable', name: 'admin_account_security_disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        $user = $this->admin();
        if ($this->required) {
            $this->addFlash('error', $this->t('admin.mfa.required_cannot_disable'));
            return $this->redirectToRoute('admin_account_security');
        }
        if (!$this->isCsrfTokenValid('admin_mfa_disable', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->t('admin.mfa.flash.csrf'));
        } elseif ($this->mfa->disable($user->id, (string) $request->request->get('code', ''))) {
            $request->getSession()->remove(AdminMfaGateSubscriber::SESSION_OK);
            $this->addFlash('success', $this->t('admin.mfa.flash.disabled'));
        } else {
            $this->addFlash('error', $this->t('admin.mfa.flash.wrong_code'));
        }
        return $this->redirectToRoute('admin_account_security');
    }

    private function admin(): AdminUser
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    private function safeTarget(mixed $target): string
    {
        return is_string($target) && preg_match('#^/admin(?:[/?\#]|$)#', $target) === 1 && !str_starts_with($target, '//') ? $target : '/admin';
    }

    private function t(string $key): string
    {
        return \Commerce\Core\I18n\CanonicalUiText::get($key);
    }
}
