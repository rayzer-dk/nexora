<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Twig;

use Commerce\Modules\Analytics\Infrastructure\TrackingSettings;
use Commerce\Modules\Security\Http\CspExtra;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TrackingExtension extends AbstractExtension
{
    public function __construct(
        private readonly TrackingSettings $settings,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tracking_tags', [$this, 'tags'])];
    }

    /** @return array{ga4:string,gtm:string,pixel:string}|null */
    public function tags(): ?array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return null;
        }
        try {
            $tags = $this->settings->storefront($this->contexts->resolve($request)->storeId);
            if ($tags === null) {
                return null;
            }
            CspExtra::merge($request, $tags['csp']);

            return ['ga4' => $tags['ga4'], 'gtm' => $tags['gtm'], 'pixel' => $tags['pixel']];
        } catch (\Throwable) {
            return null;
        }
    }
}
