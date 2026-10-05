<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Http;

use Commerce\Modules\Seo\Application\CustomRedirectService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Runs only when a page is missing, so normal requests pay nothing: first an own redirect is tried, otherwise the miss is counted. */
final class CustomRedirectSubscriber
{
    public function __construct(private readonly CustomRedirectService $redirects)
    {
    }

    #[AsEventListener(event: 'kernel.exception', priority: 10)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof NotFoundHttpException || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api') || str_starts_with($path, '/webhooks') || str_starts_with($path, '/build/') || str_starts_with($path, '/media/')) {
            return;
        }
        try {
            $match = $this->redirects->find($path);
            if ($match !== null) {
                $this->redirects->hit($match['id']);
                $event->setResponse(new RedirectResponse($match['target_url'], $match['status_code']));

                return;
            }
            if (preg_match('/\.(?:js|css|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|txt|xml|json)$/i', $path) !== 1) {
                $this->redirects->recordNotFound($path, $request->headers->get('referer'));
            }
        } catch (\Throwable) {
            // redirects are a convenience: never turn a 404 into a 500
        }
    }
}
