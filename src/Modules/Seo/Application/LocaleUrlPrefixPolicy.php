<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

final class LocaleUrlPrefixPolicy
{
    public function apply(string $routePath, string $locale, bool $isDefaultLocale, ?string $configuredPrefix = null): string
    {
        $routePath = trim($routePath, '/');
        if ($isDefaultLocale) {
            return $routePath;
        }

        $prefix = trim((string) $configuredPrefix, '/');
        if ($prefix === '') {
            $prefix = strtolower(explode('-', $locale, 2)[0]);
        }

        return $prefix === '' ? $routePath : $prefix . '/' . $routePath;
    }
}
