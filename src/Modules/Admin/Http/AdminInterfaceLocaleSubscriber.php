<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\AdminInterfaceLocale;
use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Domain\AdminUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Applies the administrator's interface language to every /admin request (after the firewall). */
final class AdminInterfaceLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AdminInterfaceLocale $locales, private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }
        $user = $this->security->getUser();
        $locale = $this->locales->resolve($request, $user instanceof AdminUser ? $user->id : null);
        $request->attributes->set(AdminInterfaceLocale::REQUEST_ATTRIBUTE, $locale);
        $request->setLocale($locale);
        CanonicalUiText::useLocale($locale);
    }
}
