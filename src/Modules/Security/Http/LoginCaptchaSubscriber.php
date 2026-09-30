<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Security\Captcha\CaptchaVerifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The storefront login is handled by the security firewall before any controller runs, so the
 * captcha for it is enforced here, one step earlier. Wrong answers never reach the password check
 * and therefore do not consume the customer's login attempts.
 */
final readonly class LoginCaptchaSubscriber
{
    public function __construct(
        private CaptchaVerifier $captcha,
        #[Autowire(service: 'limiter.public_form')] private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || $request->getPathInfo() !== '/account/login') {
            return;
        }
        if (!$this->captcha->required($request, 'login')) {
            return;
        }
        if (!$this->limiter->create('login-captcha:' . ($request->getClientIp() ?? 'unknown'))->consume(1)->isAccepted() || !$this->captcha->verify($request, 'login')) {
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', CanonicalUiText::get('flash.antibot_failed'));
            }
            $event->setResponse(new RedirectResponse('/account/login', 303));
        }
    }
}
