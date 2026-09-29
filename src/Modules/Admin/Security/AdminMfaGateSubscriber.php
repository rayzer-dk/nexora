<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Security;

use Commerce\Modules\Admin\Domain\AdminUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Second-factor gate: an administrator with two-factor enabled cannot reach any /admin page
 * until the TOTP (or recovery) code has been verified in the current session.
 */
final readonly class AdminMfaGateSubscriber implements EventSubscriberInterface
{
    public const SESSION_OK = 'admin_mfa_ok';
    public const SESSION_TARGET = 'admin_mfa_target';
    public const CHALLENGE_PATH = '/admin/2fa';

    public function __construct(private Security $security, private AdminMfaService $mfa)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Runs right after the firewall (priority 8) so the user is already authenticated.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/admin') || in_array($path, ['/admin/login', '/admin/logout', self::CHALLENGE_PATH], true)) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof AdminUser) {
            return;
        }
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session === null || $session->get(self::SESSION_OK) === $user->id) {
            return;
        }
        if (!$this->mfa->isEnabled($user->id)) {
            return;
        }
        if ($request->isMethod('GET') && !$request->isXmlHttpRequest()) {
            $session->set(self::SESSION_TARGET, $request->getRequestUri());
        }
        $event->setResponse(new RedirectResponse(self::CHALLENGE_PATH, 303));
    }
}
