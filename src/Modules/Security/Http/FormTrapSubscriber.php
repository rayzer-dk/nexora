<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Security\Spam\PublicFormSpamGuard;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bot traps for the account forms that are handled by firewalls or their own controllers: a hidden field only bots fill,
 * and a form that is sent back faster than a person can type. Pair with components/spam_fields.html.twig in the form.
 * Login only gets the hidden field: password managers may legitimately submit it at once.
 */
final readonly class FormTrapSubscriber
{
    /** path => whether the render-time check applies */
    private const FORMS = ['/account/login' => false, '/account/register' => true, '/account/password/forgot' => true, '/withdrawal' => true];

    public function __construct(private PublicFormSpamGuard $guard)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || !isset(self::FORMS[$path])) {
            return;
        }
        $honeypot = (string) $request->request->get('_website', '');
        $renderedAt = (int) $request->request->get('_rendered_at', 0);
        $timed = self::FORMS[$path];
        $result = $this->guard->check($honeypot, $timed ? $renderedAt : time() - 5, time(), 0);
        if ($result->allowed && (!$timed || $renderedAt > 0)) {
            return;
        }
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', CanonicalUiText::get('flash.antibot_failed'));
        }
        $event->setResponse(new RedirectResponse($path, 303));
    }
}
