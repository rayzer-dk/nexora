<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** A page opened with a preview link is never cached and never indexed. */
final class LayoutPreviewResponseSubscriber
{
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -100)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $event->getRequest()->query->has('_layout_preview')) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
            $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
