<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use InvalidArgumentException;

final readonly class LocalizedSeoUrlBuilder
{
    public function __construct(private LocaleUrlPrefixPolicy $prefixes)
    {
    }

    public function absolute(
        string $baseUrl,
        string $routePath,
        string $locale,
        bool $isDefaultLocale,
        ?string $configuredPrefix = null,
    ): string {
        $baseUrl = rtrim($baseUrl, '/');
        if (!preg_match('#^https://#i', $baseUrl)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4a6c10ba8ecd'));
        }

        $localized = $this->prefixes->apply($routePath, $locale, $isDefaultLocale, $configuredPrefix);
        $encoded = implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), explode('/', $localized)));

        return $baseUrl . '/' . $encoded;
    }

    /**
     * @param list<array{locale:string,path:string,is_default:bool,prefix?:?string}> $routes
     * @return array<string,string>
     */
    public function hreflang(string $baseUrl, array $routes): array
    {
        $result = [];
        $defaultUrl = null;
        foreach ($routes as $route) {
            $url = $this->absolute(
                $baseUrl,
                $route['path'],
                $route['locale'],
                $route['is_default'],
                $route['prefix'] ?? null,
            );
            $result[$route['locale']] = $url;
            if ($route['is_default']) {
                $defaultUrl = $url;
            }
        }
        if ($defaultUrl !== null) {
            $result['x-default'] = $defaultUrl;
        }

        return $result;
    }
}
