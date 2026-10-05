<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bot traps for the account forms that are handled by firewalls or their own controllers: a hidden field only bots fill. Pair with components/spam_fields.html.twig in the form.
 */
final readonly class FormTrapSubscriber
{
    private const FORMS = ['/account/login', '/account/register', '/account/password/forgot', '/withdrawal'];

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !in_array($path, self::FORMS, true)) {
            return;
        }
        // Only the hidden field: password managers and autofill may submit within a second, so no timing check here.
        if (trim((string) $request->request->get('_website', '')) === '') {
            return;
        }
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', CanonicalUiText::get('flash.antibot_failed'));
        }
        $event->setResponse(new RedirectResponse($path, 303));
    }
}
