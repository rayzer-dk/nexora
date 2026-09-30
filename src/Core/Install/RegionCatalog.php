<?php

declare(strict_types=1);

namespace Commerce\Core\Install;

/**
 * Country presets for the installer and for a fresh store: the merchant picks a country and the
 * currency, language, time zone and standard tax rate are proposed. Every value stays editable in
 * the admin; nothing here binds the platform to a country.
 */
final class RegionCatalog
{
    /** Languages that ship a storefront translation catalog. */
    public const BUNDLED_LOCALES = ['uk-UA', 'en-US', 'ru-RU', 'pl-PL', 'de-DE', 'da-DK'];

    /** @var array<string,array{0:string,1:string,2:string,3:int}> country => [currency, locale, timezone, standard VAT in basis points] */
    private const COUNTRIES = [
        'UA' => ['UAH', 'uk-UA', 'Europe/Kyiv', 2000], 'PL' => ['PLN', 'pl-PL', 'Europe/Warsaw', 2300], 'DE' => ['EUR', 'de-DE', 'Europe/Berlin', 1900],
        'DK' => ['DKK', 'da-DK', 'Europe/Copenhagen', 2500], 'US' => ['USD', 'en-US', 'America/New_York', 0], 'GB' => ['GBP', 'en-US', 'Europe/London', 2000],
        'FR' => ['EUR', 'en-US', 'Europe/Paris', 2000], 'ES' => ['EUR', 'en-US', 'Europe/Madrid', 2100], 'IT' => ['EUR', 'en-US', 'Europe/Rome', 2200],
        'NL' => ['EUR', 'en-US', 'Europe/Amsterdam', 2100], 'BE' => ['EUR', 'en-US', 'Europe/Brussels', 2100], 'AT' => ['EUR', 'de-DE', 'Europe/Vienna', 2000],
        'IE' => ['EUR', 'en-US', 'Europe/Dublin', 2300], 'PT' => ['EUR', 'en-US', 'Europe/Lisbon', 2300], 'GR' => ['EUR', 'en-US', 'Europe/Athens', 2400],
        'FI' => ['EUR', 'en-US', 'Europe/Helsinki', 2550], 'SE' => ['SEK', 'en-US', 'Europe/Stockholm', 2500], 'NO' => ['NOK', 'en-US', 'Europe/Oslo', 2500],
        'CH' => ['CHF', 'de-DE', 'Europe/Zurich', 810], 'CZ' => ['CZK', 'en-US', 'Europe/Prague', 2100], 'SK' => ['EUR', 'en-US', 'Europe/Bratislava', 2300],
        'HU' => ['HUF', 'en-US', 'Europe/Budapest', 2700], 'RO' => ['RON', 'en-US', 'Europe/Bucharest', 2100], 'BG' => ['EUR', 'en-US', 'Europe/Sofia', 2000],
        'LT' => ['EUR', 'en-US', 'Europe/Vilnius', 2100], 'LV' => ['EUR', 'en-US', 'Europe/Riga', 2100], 'EE' => ['EUR', 'en-US', 'Europe/Tallinn', 2400],
        'MD' => ['MDL', 'ru-RU', 'Europe/Chisinau', 2000], 'GE' => ['GEL', 'en-US', 'Asia/Tbilisi', 1800], 'KZ' => ['KZT', 'ru-RU', 'Asia/Almaty', 1200],
        'TR' => ['TRY', 'en-US', 'Europe/Istanbul', 2000], 'AE' => ['AED', 'en-US', 'Asia/Dubai', 500], 'IL' => ['ILS', 'en-US', 'Asia/Jerusalem', 1800],
        'CA' => ['CAD', 'en-US', 'America/Toronto', 0], 'AU' => ['AUD', 'en-US', 'Australia/Sydney', 1000], 'IN' => ['INR', 'en-US', 'Asia/Kolkata', 1800],
        'OTHER' => ['USD', 'en-US', 'UTC', 0],
    ];

    /** @var array<string,array{0:string,1:string,2:string,3:int}> code => [numeric, name, symbol, minor units] */
    private const CURRENCIES = [
        'UAH' => ['980', 'Ukrainian hryvnia', '₴', 2], 'USD' => ['840', 'US dollar', '$', 2], 'EUR' => ['978', 'Euro', '€', 2], 'GBP' => ['826', 'Pound sterling', '£', 2],
        'PLN' => ['985', 'Polish zloty', 'zł', 2], 'CZK' => ['203', 'Czech koruna', 'Kč', 2], 'DKK' => ['208', 'Danish krone', 'kr', 2], 'SEK' => ['752', 'Swedish krona', 'kr', 2],
        'NOK' => ['578', 'Norwegian krone', 'kr', 2], 'CHF' => ['756', 'Swiss franc', 'CHF', 2], 'HUF' => ['348', 'Hungarian forint', 'Ft', 2], 'RON' => ['946', 'Romanian leu', 'lei', 2],
        'BGN' => ['975', 'Bulgarian lev', 'BGN', 2], 'TRY' => ['949', 'Turkish lira', '₺', 2], 'KZT' => ['398', 'Kazakhstani tenge', '₸', 2], 'GEL' => ['981', 'Georgian lari', '₾', 2],
        'MDL' => ['498', 'Moldovan leu', 'L', 2], 'RUB' => ['643', 'Russian ruble', '₽', 2], 'BYN' => ['933', 'Belarusian ruble', 'Br', 2], 'AED' => ['784', 'UAE dirham', 'د.إ', 2],
        'SAR' => ['682', 'Saudi riyal', '﷼', 2], 'ILS' => ['376', 'Israeli shekel', '₪', 2], 'INR' => ['356', 'Indian rupee', '₹', 2], 'CNY' => ['156', 'Chinese yuan', '¥', 2],
        'JPY' => ['392', 'Japanese yen', '¥', 0], 'KRW' => ['410', 'South Korean won', '₩', 0], 'CAD' => ['124', 'Canadian dollar', '$', 2], 'AUD' => ['036', 'Australian dollar', '$', 2],
        'NZD' => ['554', 'New Zealand dollar', '$', 2], 'BRL' => ['986', 'Brazilian real', 'R$', 2], 'MXN' => ['484', 'Mexican peso', '$', 2], 'ZAR' => ['710', 'South African rand', 'R', 2],
        'SGD' => ['702', 'Singapore dollar', '$', 2], 'HKD' => ['344', 'Hong Kong dollar', '$', 2], 'THB' => ['764', 'Thai baht', '฿', 2], 'AZN' => ['944', 'Azerbaijani manat', '₼', 2],
        'AMD' => ['051', 'Armenian dram', '֏', 2], 'UZS' => ['860', 'Uzbekistani som', 'soʻm', 2], 'RSD' => ['941', 'Serbian dinar', 'din', 2], 'ISK' => ['352', 'Icelandic króna', 'kr', 0],
    ];

    /** Approximate units per 1 USD, used only to give demo products plausible prices in any currency (live rates come from the rate sources). */
    private const DEMO_RATES_PER_USD = [
        'UAH' => 41.0, 'EUR' => 0.92, 'DKK' => 6.40, 'PLN' => 4.00, 'GBP' => 0.79,
        'CZK' => 23.0, 'SEK' => 10.5, 'NOK' => 10.7, 'CHF' => 0.88, 'HUF' => 360.0, 'RON' => 4.6, 'BGN' => 1.8, 'TRY' => 40.0, 'KZT' => 500.0,
        'GEL' => 2.7, 'MDL' => 17.8, 'RUB' => 90.0, 'BYN' => 3.3, 'AED' => 3.67, 'SAR' => 3.75, 'ILS' => 3.7, 'INR' => 84.0, 'CNY' => 7.2,
        'JPY' => 150.0, 'KRW' => 1350.0, 'CAD' => 1.36, 'AUD' => 1.5, 'NZD' => 1.65, 'BRL' => 5.2, 'MXN' => 18.0, 'ZAR' => 18.0, 'SGD' => 1.34,
        'HKD' => 7.8, 'THB' => 35.0, 'AZN' => 1.7, 'AMD' => 390.0, 'UZS' => 12500.0, 'RSD' => 108.0, 'ISK' => 138.0,
    ];

    public static function demoRatePerUsd(string $currency): float
    {
        return self::DEMO_RATES_PER_USD[strtoupper($currency)] ?? 1.0;
    }

    public static function minorUnits(string $currency): int
    {
        return self::CURRENCIES[strtoupper($currency)][3] ?? 2;
    }

    /** @return list<string> */
    public static function countryCodes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    /** @return array{country:string,currency:string,locale:string,timezone:string,vat_bps:int} */
    public static function preset(string $country): array
    {
        $country = strtoupper(trim($country));
        $row = self::COUNTRIES[$country] ?? self::COUNTRIES['OTHER'];

        return ['country' => isset(self::COUNTRIES[$country]) ? $country : 'OTHER', 'currency' => $row[0], 'locale' => $row[1], 'timezone' => $row[2], 'vat_bps' => $row[3]];
    }

    /** @return array<string,array{numeric:string,name:string,symbol:string,minor_units:int}> */
    public static function currencies(): array
    {
        $out = [];
        foreach (self::CURRENCIES as $code => $row) {
            $out[$code] = ['numeric' => $row[0], 'name' => $row[1], 'symbol' => $row[2], 'minor_units' => $row[3]];
        }

        return $out;
    }

    public static function countryName(string $country, string $inLocale = 'en'): string
    {
        $country = strtoupper($country);
        if ($country === 'OTHER') {
            return $country;
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $country, $inLocale);
            if ($name !== '' && $name !== $country) {
                return $name;
            }
        }

        return $country;
    }

    public static function localeName(string $locale, string $inLocale): string
    {
        if (!class_exists(\Locale::class)) {
            return $locale;
        }
        $name = \Locale::getDisplayName($locale, $inLocale);

        return $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) : $locale;
    }
}
