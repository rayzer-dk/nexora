<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

/**
 * The flag drawn next to a language: one source (resources/icons/flags.json, built from flag-icons by
 * tools/build-flag-icons.mjs) for the storefront language switcher and every admin language tab.
 */
final class LocaleFlag
{
    /** Language subtag -> region when the locale code carries no region (e.g. "de"). English always shows the US flag. */
    private const DEFAULT_REGION = [
        'uk' => 'UA', 'en' => 'US', 'de' => 'DE', 'pl' => 'PL', 'ru' => 'RU', 'da' => 'DK', 'fr' => 'FR', 'es' => 'ES',
        'it' => 'IT', 'cs' => 'CZ', 'ro' => 'RO', 'bg' => 'BG', 'tr' => 'TR', 'pt' => 'PT', 'nl' => 'NL', 'sv' => 'SE',
        'nb' => 'NO', 'nn' => 'NO', 'no' => 'NO', 'fi' => 'FI', 'hu' => 'HU', 'sk' => 'SK', 'sl' => 'SI', 'hr' => 'HR',
        'sr' => 'RS', 'lt' => 'LT', 'lv' => 'LV', 'et' => 'EE', 'el' => 'GR', 'be' => 'BY', 'kk' => 'KZ', 'ka' => 'GE',
        'hy' => 'AM', 'az' => 'AZ', 'he' => 'IL', 'ja' => 'JP', 'zh' => 'CN', 'ko' => 'KR', 'ar' => 'SA', 'hi' => 'IN',
        'vi' => 'VN', 'th' => 'TH', 'id' => 'ID', 'ms' => 'MY', 'ga' => 'IE', 'is' => 'IS', 'mt' => 'MT', 'sq' => 'AL',
        'mk' => 'MK', 'bs' => 'BA',
    ];

    /** @var array<string,string>|null */
    private static ?array $flags = null;

    /** "uk-UA" -> UA, "de" -> DE, "en-GB" -> US, "UA" -> UA; '' when nothing fits. */
    public static function region(string $localeOrRegion): string
    {
        $value = trim(str_replace('_', '-', $localeOrRegion));
        if (preg_match('/^[A-Z]{2}$/D', $value) === 1) {
            return $value;
        }
        $parts = explode('-', $value);
        $language = strtolower($parts[0]);
        if ($language === 'en') {
            return 'US';
        }
        if (isset($parts[1]) && preg_match('/^[A-Za-z]{2}$/D', $parts[1]) === 1) {
            return strtoupper($parts[1]);
        }

        return self::DEFAULT_REGION[$language] ?? '';
    }

    /** An <img> with the flag as an inline SVG data URI (no request, no duplicate ids in the page); '' for an unknown language. */
    public static function img(string $localeOrRegion, int $width = 20): string
    {
        self::$flags ??= self::load();
        $svg = self::$flags[self::region($localeOrRegion)] ?? '';
        if ($svg === '') {
            return '';
        }
        $width = max(12, min(64, $width));

        return sprintf(
            '<img class="flag" src="data:image/svg+xml;base64,%s" width="%d" height="%d" alt="" decoding="async">',
            base64_encode($svg),
            $width,
            (int) round($width * 3 / 4),
        );
    }

    /** @return array<string,string> */
    private static function load(): array
    {
        $file = dirname(__DIR__, 3) . '/resources/icons/flags.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data['flags'] ?? null) ? $data['flags'] : [];
    }
}
