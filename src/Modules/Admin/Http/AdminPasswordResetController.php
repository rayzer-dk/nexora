<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Security\AdminPasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class AdminPasswordResetController extends AbstractController
{
    public function __construct(private readonly AdminPasswordResetService $resets)
    {
    }

    #[Route('/admin/forgot', name: 'admin_login_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        $sent = false;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_login_forgot', (string) $request->request->get('_token'))) {
                $error = CanonicalUiText::get('admin.login.error_csrf');
            } else {
                try {
                    $this->resets->request((string) $request->request->get('email', ''));
                } catch (Throwable) {
                    // Mail problems must not reveal whether the account exists.
                }
                $sent = true;
            }
        }

        return $this->render('@storefront/admin/password_forgot.html.twig', ['sent' => $sent, 'error' => $error]);
    }

    #[Route('/admin/reset/{token}', name: 'admin_login_recover', methods: ['GET', 'POST'], requirements: ['token' => '[A-Za-z0-9_-]{32,80}'])]
    public function reset(Request $request, string $token): Response
    {
        $usable = $this->resets->isUsable($token);
        $error = null;
        if ($usable && $request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            if (!$this->isCsrfTokenValid('admin_login_recover', (string) $request->request->get('_token'))) {
                $error = CanonicalUiText::get('admin.login.error_csrf');
            } elseif ($password !== (string) $request->request->get('password_confirm', '')) {
                $error = CanonicalUiText::get('admin.reset.mismatch');
            } else {
                try {
                    if ($this->resets->reset($token, $password)) {
                        $this->addFlash('success', CanonicalUiText::get('admin.reset.done'));

                        return $this->redirectToRoute('admin_login');
                    }
                    $usable = false;
                } catch (\DomainException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return $this->render('@storefront/admin/password_recover.html.twig', ['usable' => $usable, 'error' => $error]);
    }
}
