<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class AdminLoginController extends AbstractController
{
    #[Route('/admin/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        // Distinguish the real reason: a throttled or CSRF/session failure is not "wrong password".
        $errorCode = match (true) {
            $error === null => null,
            $error instanceof TooManyLoginAttemptsAuthenticationException => 'throttled',
            $error instanceof InvalidCsrfTokenException => 'csrf',
            $error instanceof BadCredentialsException => 'credentials',
            default => 'credentials',
        };

        return $this->render('@storefront/admin/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $error,
            'error_code' => $errorCode,
        ]);
    }

    #[Route('/admin/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.aa848decf053'));
    }
}
