<?php

declare(strict_types=1);

namespace Commerce\Core\Site;

use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SiteCapabilityAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SiteCapabilitySettings $settings,
        private StorefrontContextResolver $contexts,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', 30]];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || str_starts_with($route, 'admin_') || str_starts_with($route, 'commerce_web_install')) { return; }
        $feature = $this->featureForRoute($route);
        if ($feature === null) { return; }

        try {
            $context = $this->contexts->resolve($request);
            if (!$this->settings->enabled($context->storeId, $feature)) {
                throw new NotFoundHttpException(\Commerce\Core\I18n\CanonicalUiText::get('php.core.site.sitecapabilityaccesssubscriber.tsei_rozdil_vymkneno_v_konfihuratsii_saitu'));
            }
        } catch (NotFoundHttpException $e) {
            throw $e;
        } catch (\Throwable) {
            // Presentation gating must not turn a transient configuration failure into a full storefront outage.
        }
    }

    private function featureForRoute(string $route): ?string
    {
        if (str_starts_with($route, 'storefront_forum')) return 'forum';
        if ($route === 'storefront_search_suggest') return 'search';
        if (str_starts_with($route, 'storefront_cart')) return 'cart';
        if (str_starts_with($route, 'storefront_checkout') || str_starts_with($route, 'payment_')) return 'checkout';
        if (str_starts_with($route, 'storefront_catalog') || str_starts_with($route, 'storefront_product') || str_starts_with($route, 'storefront_category')) return 'catalog';
        if (str_starts_with($route, 'storefront_blog')) return 'blog';
        if (str_starts_with($route, 'storefront_information') || str_starts_with($route, 'content_')) return 'content';
        return null;
    }
}
