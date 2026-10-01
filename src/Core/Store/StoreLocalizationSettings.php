<?php

declare(strict_types=1);

namespace Commerce\Core\Store;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use DomainException;

/**
 * Languages and currencies offered by one store.
 *
 * The default language/currency are owned by the store settings page; here they are always kept enabled.
 * A currency is visible to shoppers only when it has prices (auto-converted or entered manually), which
 * StorefrontContextResolver enforces, so enabling a currency can never produce products without a price.
 */
final class StoreLocalizationSettings
{
    /** Values of mc_store_currency.rate_source: official rates ("auto" = ECB, then NBU), one central bank, a keyed API, or typed by hand. */
    public const RATE_SOURCES = ['auto', 'ecb', 'nbu', 'nbp', 'cnb', 'api', 'manual'];
    /** Upper bound of the safety markup added to converted prices, in basis points (20%). */
    public const MAX_MARKUP_BPS = 2000;

    public function __construct(private readonly Connection $db, private readonly string $projectDir)
    {
    }

    /** @return array{locales:list<array<string,mixed>>,currencies:list<array<string,mixed>>,default_locale:string,default_currency:string} */
    public function overview(int $storeId): array
    {
        $store = $this->db->fetchAssociative('SELECT default_locale,default_currency FROM mc_store WHERE id=?', [$storeId]) ?: ['default_locale' => 'uk-UA', 'default_currency' => 'UAH'];
        $locales = $this->db->fetchAllAssociative(
            'SELECT l.code,l.name,l.native_name,COALESCE(sl.enabled,0) enabled,COALESCE(sl.sort_order,100) sort_order,sl.locale_code IS NOT NULL attached
             FROM mc_locale l LEFT JOIN mc_store_locale sl ON sl.locale_code=l.code AND sl.store_id=?
             WHERE l.enabled=1 ORDER BY COALESCE(sl.sort_order,100),l.code',
            [$storeId],
        );
        foreach ($locales as &$localeRow) {
            $localeRow['code'] = $this->scalarText($localeRow['code'] ?? '');
            $localeRow['name'] = $this->localizedText($localeRow['name'] ?? '', $localeRow['code'], 'en');
            $localeRow['native_name'] = $this->localizedText($localeRow['native_name'] ?? '', $localeRow['code'], $localeRow['code']);
        }
        unset($localeRow);

        // Languages shipped with storefront translations but not registered yet are offered with one checkbox.
        $registered = array_column($locales, 'code');
        foreach ($this->bundledLocales() as $code) {
            if (!in_array($code, $registered, true)) {
                $locales[] = ['code' => $code, 'name' => $this->displayName($code, 'en'), 'native_name' => $this->displayName($code, $code), 'enabled' => 0, 'sort_order' => 100, 'attached' => 0];
            }
        }
        $reference = $this->catalogSize('uk-UA');
        foreach ($locales as &$locale) {
            $locale['is_default'] = $locale['code'] === $store['default_locale'];
            $size = $this->catalogSize((string) $locale['code']);
            $locale['ui_coverage'] = $reference > 0 ? min(100, (int) floor($size * 100 / $reference)) : 0;
            $locale['content_count'] = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_translation WHERE locale=?', [$locale['code']]);
        }
        unset($locale);

        $currencies = $this->db->fetchAllAssociative(
            "SELECT c.code,c.name,c.symbol,c.minor_units,COALESCE(sc.enabled,0) enabled,COALESCE(sc.auto_convert,0) auto_convert,
                    COALESCE(sc.rate_source,'auto') rate_source,COALESCE(sc.rate_markup_bps,0) rate_markup_bps,COALESCE(sc.rounding_increment_minor,1) rounding_increment_minor,COALESCE(sc.sort_order,100) sort_order
             FROM mc_currency c LEFT JOIN mc_store_currency sc ON sc.currency_code=c.code AND sc.store_id=?
             WHERE c.enabled=1 ORDER BY COALESCE(sc.sort_order,100),c.code",
            [$storeId],
        );
        foreach ($currencies as &$currency) {
            $currency['is_default'] = $currency['code'] === $store['default_currency'];
            $currency['price_count'] = (int) $this->db->fetchOne('SELECT COUNT(DISTINCT variant_id) FROM mc_price WHERE store_id=? AND currency=?', [$storeId, $currency['code']]);
            $currency['manual_price_count'] = (int) $this->db->fetchOne("SELECT COUNT(DISTINCT variant_id) FROM mc_price WHERE store_id=? AND currency=? AND source='manual'", [$storeId, $currency['code']]);
            $currency['rate'] = $this->latestRate((string) $store['default_currency'], (string) $currency['code'], (string) $currency['rate_source']);
        }
        unset($currency);

        return ['locales' => $locales, 'currencies' => $currencies, 'default_locale' => (string) $store['default_locale'], 'default_currency' => (string) $store['default_currency']];
    }

    /** @param array<string,array{enabled?:mixed,sort_order?:mixed}> $rows */
    public function saveLocales(int $storeId, array $rows): void
    {
        $default = (string) $this->db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);
        $known = array_flip($this->db->fetchFirstColumn('SELECT code FROM mc_locale WHERE enabled=1'));
        foreach ($rows as $code => $row) {
            if (!isset($known[$code]) && !empty($row['enabled']) && in_array($code, $this->bundledLocales(), true)) {
                $known[$this->addLocale((string) $code, '', '')] = true;
            }
        }
        $this->db->transactional(function (Connection $db) use ($storeId, $rows, $default, $known): void {
            foreach ($rows as $code => $row) {
                if (!isset($known[$code])) {
                    continue;
                }
                $enabled = $code === $default || !empty($row['enabled']) ? 1 : 0;
                $sort = max(0, min(9999, (int) ($row['sort_order'] ?? 100)));
                $db->executeStatement(
                    'INSERT INTO mc_store_locale (store_id,locale_code,enabled,is_default,sort_order) VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),sort_order=VALUES(sort_order)',
                    [$storeId, $code, $enabled, $code === $default ? 1 : 0, $sort],
                );
            }
        });
    }

    public function addLocale(string $code, string $name, string $nativeName): string
    {
        $code = trim($code);
        if (preg_match('/^([a-z]{2,3})(?:-([A-Z]{2}))?$/', $code, $m) !== 1) {
            throw new DomainException(CanonicalUiText::get('admin.localization.locale.invalid_code'));
        }
        $name = mb_substr(trim($name) !== '' ? trim($name) : $this->displayName($code, 'en'), 0, 190);
        $nativeName = mb_substr(trim($nativeName) !== '' ? trim($nativeName) : $this->displayName($code, $code), 0, 190);
        if ($name === '' || $nativeName === '') {
            throw new DomainException(CanonicalUiText::get('admin.localization.locale.name_required'));
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->executeStatement(
            'INSERT INTO mc_locale (code,language_code,region_code,name,native_name,direction,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,1,?,?)
             ON DUPLICATE KEY UPDATE name=VALUES(name),native_name=VALUES(native_name),enabled=1,updated_at=VALUES(updated_at)',
            [$code, $m[1], $m[2] ?? null, $name, $nativeName, in_array($m[1], ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr', $now, $now],
        );

        return $code;
    }

    /** @param array<string,array{enabled?:mixed,auto_convert?:mixed,rate_source?:mixed,rate_markup?:mixed,rounding_increment_minor?:mixed,sort_order?:mixed}> $rows */
    public function saveCurrencies(int $storeId, array $rows): void
    {
        $default = strtoupper((string) $this->db->fetchOne('SELECT default_currency FROM mc_store WHERE id=?', [$storeId]));
        $known = array_flip($this->db->fetchFirstColumn('SELECT code FROM mc_currency WHERE enabled=1'));
        $this->db->transactional(function (Connection $db) use ($storeId, $rows, $default, $known): void {
            foreach ($rows as $code => $row) {
                $code = strtoupper((string) $code);
                if (!isset($known[$code])) {
                    continue;
                }
                $isDefault = $code === $default;
                $source = strtolower(trim((string) ($row['rate_source'] ?? 'auto')));
                $source = in_array($source, self::RATE_SOURCES, true) ? $source : 'auto';
                $markupBps = self::markupToBps($row['rate_markup'] ?? 0);
                $rounding = (int) ($row['rounding_increment_minor'] ?? 1);
                $rounding = in_array($rounding, [1, 5, 10, 50, 100], true) ? $rounding : 1;
                $db->executeStatement(
                    'INSERT INTO mc_store_currency (store_id,currency_code,enabled,is_default,auto_convert,rate_source,rate_markup_bps,rounding_increment_minor,sort_order) VALUES (?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),auto_convert=VALUES(auto_convert),rate_source=VALUES(rate_source),rate_markup_bps=VALUES(rate_markup_bps),rounding_increment_minor=VALUES(rounding_increment_minor),sort_order=VALUES(sort_order)',
                    [$storeId, $code, $isDefault || !empty($row['enabled']) ? 1 : 0, $isDefault ? 1 : 0, !$isDefault && !empty($row['auto_convert']) ? 1 : 0, $source, $isDefault ? 0 : $markupBps, $rounding, max(0, min(9999, (int) ($row['sort_order'] ?? 100)))],
                );
            }
        });
    }

    /** "2.5" or "2,5" (percent) to basis points, clamped to 0..MAX_MARKUP_BPS. */
    public static function markupToBps(mixed $percent): int
    {
        $value = (float) str_replace(',', '.', trim((string) $percent));

        return max(0, min(self::MAX_MARKUP_BPS, (int) round($value * 100)));
    }

    public function addCurrency(string $code, string $name, string $symbol, int $minorUnits): string
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new DomainException(CanonicalUiText::get('admin.localization.currency.invalid_code'));
        }
        $name = mb_substr(trim($name), 0, 190);
        if ($name === '') {
            throw new DomainException(CanonicalUiText::get('admin.localization.currency.name_required'));
        }
        $now = gmdate('Y-m-d H:i:s.u');
        $this->db->executeStatement(
            'INSERT INTO mc_currency (code,name,symbol,minor_units,enabled,created_at,updated_at) VALUES (?,?,?,?,1,?,?)
             ON DUPLICATE KEY UPDATE name=VALUES(name),symbol=VALUES(symbol),minor_units=VALUES(minor_units),enabled=1,updated_at=VALUES(updated_at)',
            [$code, $name, mb_substr(trim($symbol), 0, 16) ?: null, max(0, min(4, $minorUnits)), $now, $now],
        );

        return $code;
    }

    /** @return array{rate:string,provider:string,observed_at:string,expires_at:?string}|null */
    private function latestRate(string $base, string $quote, string $source): ?array
    {
        if ($base === $quote) {
            return null;
        }
        $row = $this->db->fetchAssociative(
            'SELECT base_currency,rate,provider,observed_at,expires_at FROM mc_exchange_rate
             WHERE ((base_currency=? AND quote_currency=?) OR (base_currency=? AND quote_currency=?)) AND ' . ($source === 'manual' ? "provider='manual'" : ($source === 'auto' ? "provider<>'manual'" : 'provider=?')) . '
             ORDER BY observed_at DESC,id DESC LIMIT 1',
            in_array($source, ['manual', 'auto'], true) ? [$base, $quote, $quote, $base] : [$base, $quote, $quote, $base, $source],
        );
        if (!is_array($row)) {
            return null;
        }
        $rate = (float) $row['rate'];
        if (strtoupper((string) $row['base_currency']) !== $base && $rate > 0) {
            $rate = 1 / $rate;
        }

        return [
            'rate' => rtrim(rtrim(sprintf('%.6F', $rate), '0'), '.'),
            'per_quote' => $rate > 0 ? rtrim(rtrim(sprintf('%.4F', 1 / $rate), '0'), '.') : '',
            'provider' => (string) $row['provider'],
            'observed_at' => substr((string) $row['observed_at'], 0, 16),
            'expires_at' => $row['expires_at'] !== null ? substr((string) $row['expires_at'], 0, 16) : null,
            'expired' => $row['expires_at'] !== null && strtotime((string) $row['expires_at'] . ' UTC') < time(),
        ];
    }

    /** @return list<string> locale codes that ship a storefront translation catalog */
    private function bundledLocales(): array
    {
        $codes = [];
        foreach (glob($this->projectDir . '/resources/translations/*/storefront.php') ?: [] as $file) {
            $code = basename(dirname($file));
            if (preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $code) === 1) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function scalarText(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return trim((string) $value);
        }

        return '';
    }

    private function localizedText(mixed $value, string $code, string $fallbackLocale): string
    {
        if (is_array($value)) {
            foreach ([$code, str_replace('-', '_', $code), $fallbackLocale, 'en', 'uk-UA', 'uk_UA'] as $key) {
                if (array_key_exists($key, $value)) {
                    $text = $this->scalarText($value[$key]);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
            foreach ($value as $candidate) {
                $text = $this->scalarText($candidate);
                if ($text !== '') {
                    return $text;
                }
            }
            return $this->displayName($code, $fallbackLocale);
        }

        $text = $this->scalarText($value);
        return $text !== '' ? $text : $this->displayName($code, $fallbackLocale);
    }

    private function displayName(string $code, string $inLocale): string
    {
        if (!class_exists(\Locale::class)) {
            return $code;
        }
        $name = $inLocale === $code ? \Locale::getDisplayLanguage($code, $code) : \Locale::getDisplayName($code, $inLocale);

        return $name !== '' ? mb_convert_case(mb_substr($name, 0, 1), MB_CASE_UPPER) . mb_substr($name, 1) : $code;
    }

    private function catalogSize(string $locale): int
    {
        static $sizes = [];
        if (isset($sizes[$locale])) {
            return $sizes[$locale];
        }
        $file = $this->projectDir . '/resources/translations/' . basename($locale) . '/storefront.php';
        $data = is_file($file) ? include $file : [];

        return $sizes[$locale] = is_array($data) ? count($data) : 0;
    }
}
