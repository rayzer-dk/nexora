<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

require_once __DIR__ . '/TranslationCatalogLoader.php';

final class CanonicalUiText
{
    private static string $locale = 'uk-UA';

    /** @var array<string,array<string,string>> */
    private static array $catalogs = [];

    public static function useLocale(string $locale): void
    {
        $locale = str_replace('_', '-', trim($locale));
        self::$locale = $locale !== '' ? $locale : 'uk-UA';
    }

    /** @param array<string,scalar|null> $replace */
    public static function get(string $key, array $replace = []): string
    {
        $catalog = self::catalog(self::$locale);
        $value = $catalog[$key] ?? $key;
        foreach ($replace as $name => $replacement) {
            $value = str_replace('%' . $name . '%', (string) $replacement, $value);
        }
        return $value;
    }

    /** @return array<string,string> */
    private static function catalog(string $locale): array
    {
        if (isset(self::$catalogs[$locale])) {
            return self::$catalogs[$locale];
        }

        $loader = new TranslationCatalogLoader(dirname(__DIR__, 3));
        $fallback = $loader->load('uk-UA');
        if ($locale === 'uk-UA') {
            return self::$catalogs[$locale] = $fallback;
        }

        return self::$catalogs[$locale] = array_replace($fallback, $loader->load($locale));
    }
}
