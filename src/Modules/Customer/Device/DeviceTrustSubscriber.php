<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Device;

use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Fraud\Application\FraudService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Starts the device challenge after a password sign-in from an unknown browser and gates the account until it is passed. */
final readonly class DeviceTrustSubscriber
{
    public const SESSION_KEY = 'device_pending';
    private const GATED = ['/account', '/checkout'];
    private const OPEN = ['/account/device-verify', '/account/logout'];

    public function __construct(
        private DeviceTrustService $devices,
        private FraudService $settings,
        private StorefrontContextResolver $contexts,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLogin(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $request = $event->getRequest();
        if (!$user instanceof CustomerUser || $event->getFirewallName() !== 'customer' || $request->attributes->get('_device_skip') === true) {
            return;
        }
        try {
            $context = $this->contexts->resolve($request);
            if (!$this->settings->settings($context->storeId)['device_confirm']) {
                return;
            }
            if ($this->devices->isTrusted($user->id(), $request->cookies->get(DeviceTrustService::COOKIE))) {
                return;
            }
            $request->getSession()->set(self::SESSION_KEY, true);
            $this->devices->challenge($user->id(), $context->storeName);
        } catch (\Throwable) {
            // Fail open on infrastructure errors: never lock customers out because of a settings/mail outage.
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession() || $request->getSession()->get(self::SESSION_KEY) !== true) {
            return;
        }
        $path = $request->getPathInfo();
        foreach (self::OPEN as $open) {
            if ($path === $open) {
                return;
            }
        }
        foreach (self::GATED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $event->setResponse(new RedirectResponse('/account/device-verify', 303));

                return;
            }
        }
    }
}
