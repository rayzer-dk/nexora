<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

require_once __DIR__ . '/TranslationCatalogLoader.php';

final class CanonicalUiText
{
    /** @var array<string,string>|null */
    private static ?array $catalog = null;

    /** @param array<string,scalar|null> $replace */
    public static function get(string $key, array $replace = []): string
    {
        $catalog = self::catalog();
        $value = $catalog[$key] ?? $key;
        foreach ($replace as $name => $replacement) {
            $value = str_replace('%' . $name . '%', (string) $replacement, $value);
        }
        return $value;
    }

    /** @return array<string,string> */
    private static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $loader = new TranslationCatalogLoader(dirname(__DIR__, 3));
        return self::$catalog = $loader->load('uk-UA');
    }
}
