<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Http;

use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class StorefrontFeatureAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private StorefrontContextResolver $contexts,
        private SiteCapabilitySettings $capabilities,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = '/' . ltrim($request->getPathInfo(), '/');
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/setup.php')) {
            return;
        }

        $feature = $this->featureForPath($path);
        if ($feature === null) {
            return;
        }

        try {
            $context = $this->contexts->resolve($request);
        } catch (\Throwable) {
            return;
        }

        if (!$this->capabilities->enabled($context->storeId, $feature)) {
            throw new NotFoundHttpException();
        }
    }

    private function featureForPath(string $path): ?string
    {
        $map = [
            '/api/storefront/search' => 'search',
            '/api/storefront/catalog' => 'catalog',
            '/checkout' => 'checkout',
            '/catalog' => 'catalog',
            '/product/' => 'catalog',
            '/compare' => 'catalog',
            '/cart' => 'cart',
            '/forum' => 'forum',
            '/account' => 'customer_accounts',
            '/blog' => 'blog',
        ];

        foreach ($map as $prefix => $feature) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') || str_ends_with($prefix, '/') && str_starts_with($path, $prefix)) {
                return $feature;
            }
        }

        return null;
    }
}
