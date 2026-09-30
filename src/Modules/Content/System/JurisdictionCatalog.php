<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\System;

/**
 * The legal frame quoted in the ready-made information pages, by the country the store operates in.
 * It only names the applicable laws and the complaint body so the draft is not wrong for the country;
 * the drafts remain drafts and the merchant (or their lawyer) stays responsible for the final text.
 */
final class JurisdictionCatalog
{
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO',
    ];

    public const KEYS = ['privacy_law', 'privacy_authority', 'consumer_law', 'cookie_law'];

    /** @var array<string,array<string,array<string,string>>>|null group => key => language => text, loaded from resources/content/information/jurisdictions.json */
    private static ?array $text = null;

    /** @return array<string,array<string,array<string,string>>> */
    private static function text(): array
    {
        if (self::$text === null) {
            $file = dirname(__DIR__, 4) . '/resources/content/information/jurisdictions.json';
            $decoded = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
            self::$text = is_array($decoded) ? $decoded : [];
        }

        return self::$text;
    }

    public static function group(string $country): string
    {
        $country = strtoupper(trim($country));

        return match (true) {
            $country === 'UA' => 'ua',
            $country === 'GB' => 'gb',
            $country === 'US' => 'us',
            in_array($country, self::EU, true) => 'eu',
            default => 'other',
        };
    }

    /** @return array<string,string> placeholder => text for the country, in the given language (English when there is no translation) */
    public static function values(string $country, string $language): array
    {
        $language = strtolower(substr($language, 0, 2));
        $group = self::text()[self::group($country)];
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $group[$key][$language] ?? $group[$key]['en'];
        }

        return $out;
    }
}
