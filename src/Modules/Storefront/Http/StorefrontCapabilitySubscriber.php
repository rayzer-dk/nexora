<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Http;

use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class StorefrontCapabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private StorefrontContextResolver $contexts,
        private SiteCapabilitySettings $capabilities,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', 24]];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || str_starts_with($route, 'admin_') || str_starts_with($route, 'api_')) {
            return;
        }

        $feature = $this->featureForRoute($route);
        if ($feature === null) {
            return;
        }

        $context = $this->contexts->resolve($request);
        if (!$this->capabilities->enabled($context->storeId, $feature)) {
            throw new NotFoundHttpException(\Commerce\Core\I18n\CanonicalUiText::get('http.error.not_found'));
        }

        if ($route === 'storefront_catalog' && trim((string) $request->query->get('q', '')) !== ''
            && !$this->capabilities->enabled($context->storeId, 'search')) {
            throw new NotFoundHttpException(\Commerce\Core\I18n\CanonicalUiText::get('http.error.not_found'));
        }
    }

    private function featureForRoute(string $route): ?string
    {
        if ($route === 'storefront_search_suggest') {
            return 'search';
        }
        if ($route === 'storefront_catalog' || $route === 'storefront_catalog_cursor'
            || str_starts_with($route, 'product_compare')
            || str_starts_with($route, 'storefront_product_inquiry')
            || str_starts_with($route, 'storefront_stock_notify')) {
            return 'catalog';
        }
        if (str_starts_with($route, 'storefront_cart') || $route === 'customer_saved_cart_save') {
            return 'cart';
        }
        if (str_starts_with($route, 'storefront_checkout')) {
            return 'checkout';
        }
        if (str_starts_with($route, 'storefront_forum')) {
            return 'forum';
        }
        if ($route === 'storefront_information_page') {
            return 'content';
        }
        if ($route === 'storefront_blog') {
            return 'blog';
        }
        if (str_starts_with($route, 'product_review') || str_starts_with($route, 'product_question')) {
            return 'reviews';
        }
        if (str_starts_with($route, 'customer_')) {
            return 'customer_accounts';
        }

        return null;
    }
}
