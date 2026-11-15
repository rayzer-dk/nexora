<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

/**
 * A currency named in the reader's language ("долар США", "украинская гривна", "Euro") from the ICU data; the name stored
 * with the currency (English) only for a currency or a language ICU does not know.
 */
final class CurrencyName
{
    /** @var array<string,\ResourceBundle|null> */
    private static array $bundles = [];

    public static function localized(string $code, string $locale, string $fallback = ''): string
    {
        $code = strtoupper($code);
        $bundle = self::$bundles[$locale] ??= (\ResourceBundle::create(str_replace('-', '_', $locale), 'ICUDATA-curr') ?: null);
        $names = $bundle?->get('Currencies');
        $entry = $names instanceof \ResourceBundle ? $names->get($code) : null;
        $name = $entry instanceof \ResourceBundle ? (string) $entry->get(1) : '';
        if ($name === '') {
            return $fallback !== '' ? $fallback : $code;
        }

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }
}
