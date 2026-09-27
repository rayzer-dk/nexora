<?php

declare(strict_types=1);

namespace Commerce\Core\Extension;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ExtensionAssetTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ExtensionContributionRegistry $registry,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('extension_assets', [$this, 'assets'])];
    }

    /** @return list<array<string,mixed>> */
    public function assets(): array
    {
        $scopes = ['storefront.global'];
        $route = (string) ($this->requests->getCurrentRequest()?->attributes->get('_route') ?? '');
        if ($route !== '') {
            $scopes[] = 'route:' . $route;
        }
        $out = [];
        foreach ($scopes as $scope) {
            foreach ($this->registry->assetsFor($scope) as $asset) {
                $code = rawurlencode((string) ($asset['extension_code'] ?? ''));
                $version = rawurlencode((string) ($asset['version'] ?? ''));
                $path = implode('/', array_map('rawurlencode', explode('/', (string) ($asset['path'] ?? ''))));
                $asset['url'] = '/media/extensions/' . $code . '/' . $version . '/' . $path;
                $out[] = $asset;
            }
        }
        return $out;
    }
}
