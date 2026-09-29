<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Application;

use Doctrine\DBAL\Connection;

/**
 * Delivery countries and regions. A store with no configured countries delivers everywhere (backward compatible);
 * once at least one country is saved, checkout is allowed only for enabled countries.
 */
final class ShippingCountryService
{
    private const EXCLUDED = ['EU', 'UN', 'EZ', 'ZZ', 'XA', 'XB', 'QO', 'AC', 'CP', 'DG', 'EA', 'IC', 'TA'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<string> */
    public static function allCountryCodes(): array
    {
        $codes = [];
        $bundle = \ResourceBundle::create('en', 'ICUDATA-region');
        $countries = $bundle instanceof \ResourceBundle ? $bundle->get('Countries') : null;
        foreach ($countries instanceof \ResourceBundle ? array_keys(iterator_to_array($countries)) : [] as $code) {
            $code = (string) $code;
            if (preg_match('/^[A-Z]{2}$/', $code) === 1 && !in_array($code, self::EXCLUDED, true)) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $codes;
    }

    public static function countryName(string $code, string $locale): string
    {
        $name = \Locale::getDisplayRegion('-' . $code, str_replace('_', '-', $locale));

        return is_string($name) && $name !== '' && $name !== $code ? $name : $code;
    }

    /** @return array<string,bool> code => enabled (only configured countries) */
    public function configured(int $storeId): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT country_code,enabled FROM mc_shipping_country WHERE store_id=?', [$storeId]) as $row) {
            $out[(string) $row['country_code']] = (bool) $row['enabled'];
        }

        return $out;
    }

    public function allows(int $storeId, string $countryCode): bool
    {
        try {
            $configured = $this->configured($storeId);
        } catch (\Throwable) {
            return true;
        }

        return $configured === [] || ($configured[strtoupper($countryCode)] ?? false);
    }

    /** @param list<string> $enabledCodes */
    public function saveCountries(int $storeId, array $enabledCodes): void
    {
        $valid = array_flip(self::allCountryCodes());
        $enabledCodes = array_values(array_unique(array_filter(array_map('strtoupper', $enabledCodes), static fn (string $c): bool => isset($valid[$c]))));
        if ($enabledCodes === []) {
            throw new \DomainException('shipping_countries_empty');
        }
        $this->db->transactional(function (Connection $db) use ($storeId, $enabledCodes): void {
            $db->delete('mc_shipping_country', ['store_id' => $storeId]);
            foreach ($enabledCodes as $code) {
                $db->insert('mc_shipping_country', ['store_id' => $storeId, 'country_code' => $code, 'enabled' => 1]);
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function regions(int $storeId, string $country): array
    {
        return $this->db->fetchAllAssociative('SELECT id,code,name,enabled FROM mc_shipping_region WHERE store_id=? AND country_code=? ORDER BY sort_order,name', [$storeId, strtoupper($country)]);
    }

    public function importDefaultRegions(int $storeId, string $country): int
    {
        $country = strtoupper($country);
        $n = 0;
        $i = 0;
        $file = dirname(__DIR__, 4) . '/resources/data/shipping-regions.json';
        $defaults = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        foreach ((array) ($defaults[$country] ?? []) as $code => $name) {
            $n += $this->db->executeStatement('INSERT IGNORE INTO mc_shipping_region (store_id,country_code,code,name,enabled,sort_order) VALUES (?,?,?,?,1,?)', [$storeId, $country, $code, $name, $i += 10]);
        }

        return $n;
    }

    public function addRegion(int $storeId, string $country, string $name): void
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 190);
        $code = mb_substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $name) ?: $name)), '-'), 0, 32);
        if ($name === '' || $code === '') {
            throw new \DomainException('region_invalid');
        }
        $this->db->executeStatement('INSERT IGNORE INTO mc_shipping_region (store_id,country_code,code,name,enabled,sort_order) VALUES (?,?,?,?,1,(SELECT COALESCE(MAX(sort_order),0)+10 FROM (SELECT sort_order FROM mc_shipping_region WHERE store_id=? AND country_code=?) t))', [$storeId, strtoupper($country), $code, $name, $storeId, strtoupper($country)]);
    }

    /** @param list<int> $enabledIds */
    public function saveRegionStates(int $storeId, string $country, array $enabledIds): void
    {
        $this->db->executeStatement('UPDATE mc_shipping_region SET enabled=0 WHERE store_id=? AND country_code=?', [$storeId, strtoupper($country)]);
        foreach ($enabledIds as $id) {
            $this->db->executeStatement('UPDATE mc_shipping_region SET enabled=1 WHERE id=? AND store_id=? AND country_code=?', [$id, $storeId, strtoupper($country)]);
        }
    }

    public function deleteRegion(int $storeId, int $id): void
    {
        $this->db->delete('mc_shipping_region', ['id' => $id, 'store_id' => $storeId]);
    }
}
