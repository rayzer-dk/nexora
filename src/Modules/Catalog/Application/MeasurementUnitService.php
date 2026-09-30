<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/**
 * Sale units: the built-in catalogue plus merchant-defined units. Product forms and the
 * storefront read from this table instead of a hard-coded list.
 */
final class MeasurementUnitService
{
    public const DIMENSIONS = ['count', 'mass', 'volume', 'length', 'area', 'time'];
    private const BUILTIN = ['item', 'ct', 'set', 'pair', 'pack', 'sheet', 'roll', 'kg', 'g', 'mg', 't', 'l', 'ml', 'cl', 'cbm', 'm', 'cm', 'mm', 'sqm', 'hour'];

    /** Built-in names for English interfaces when no translation row exists. */
    private const ENGLISH = [
        'item' => ['Piece', 'pcs'], 'ct' => ['Unit', 'unit'], 'set' => ['Set', 'set'], 'pair' => ['Pair', 'pair'], 'pack' => ['Pack', 'pack'],
        'sheet' => ['Sheet', 'sheet'], 'roll' => ['Roll', 'roll'], 'kg' => ['Kilogram', 'kg'], 'g' => ['Gram', 'g'], 'mg' => ['Milligram', 'mg'],
        't' => ['Tonne', 't'], 'l' => ['Litre', 'l'], 'ml' => ['Millilitre', 'ml'], 'cl' => ['Centilitre', 'cl'], 'cbm' => ['Cubic metre', 'm³'],
        'm' => ['Metre', 'm'], 'cm' => ['Centimetre', 'cm'], 'mm' => ['Millimetre', 'mm'], 'sqm' => ['Square metre', 'm²'], 'hour' => ['Hour', 'h'],
    ];

    /** @var array<string,list<array<string,mixed>>> */
    private array $cache = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array{code:string,dimension:string,symbol:string,name:string,short:string,decimal_scale:int,enabled:bool,builtin:bool,sort_order:int}> */
    public function all(string $locale, bool $onlyEnabled = true): array
    {
        $key = $locale . ($onlyEnabled ? ':1' : ':0');
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $lang = substr($locale, 0, 2);
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.code,u.dimension,u.symbol,u.decimal_scale,u.enabled,u.sort_order,
                    COALESCE(t.name, tl.name) AS name, COALESCE(t.short_name, tl.short_name) AS short_name, tu.name AS any_name, tu.short_name AS any_short
             FROM mc_measurement_unit u
             LEFT JOIN mc_measurement_unit_translation t ON t.unit_code=u.code AND t.locale=?
             LEFT JOIN mc_measurement_unit_translation tl ON tl.unit_code=u.code AND tl.locale=(SELECT MIN(x.locale) FROM mc_measurement_unit_translation x WHERE x.unit_code=u.code AND x.locale LIKE ?)
             LEFT JOIN mc_measurement_unit_translation tu ON tu.unit_code=u.code AND tu.locale=(SELECT MIN(y.locale) FROM mc_measurement_unit_translation y WHERE y.unit_code=u.code)'
            . ($onlyEnabled ? ' WHERE u.enabled=1' : '') . ' ORDER BY u.sort_order,u.code',
            [$locale, $lang . '-%'],
        );

        return $this->cache[$key] = array_map(static function (array $r) use ($lang): array {
            $english = $lang === 'en' && ($r['name'] === null || $r['name'] === '') ? self::ENGLISH[(string) $r['code']] ?? null : null;

            return [
            'code' => (string) $r['code'],
            'dimension' => (string) $r['dimension'],
            'symbol' => (string) $r['symbol'],
            'name' => (string) ($english[0] ?? ($r['name'] ?: ($r['any_name'] ?: $r['code']))),
            'short' => (string) ($english[1] ?? ($r['short_name'] ?: ($r['any_short'] ?: $r['symbol']))),
            'decimal_scale' => (int) $r['decimal_scale'],
            'enabled' => (bool) $r['enabled'],
            'builtin' => in_array((string) $r['code'], self::BUILTIN, true),
            'sort_order' => (int) $r['sort_order'],
            ];
        }, $rows);
    }

    public function shortLabel(string $code, string $locale): string
    {
        foreach ($this->all($locale, false) as $unit) {
            if ($unit['code'] === $code) {
                return $unit['short'];
            }
        }

        return $code;
    }

    /**
     * @param array<string,array{name:string,short:string}> $names locale => names
     */
    public function create(string $code, string $dimension, int $decimalScale, array $names): string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            foreach ($names as $n) {
                $code = $this->slug($n['name']);
                if ($code !== '') {
                    break;
                }
            }
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $code) !== 1) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.units.err_code'));
        }
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.units.err_dimension'));
        }
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_measurement_unit WHERE code=?', [$code]) > 0) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.units.err_exists'));
        }
        $clean = [];
        foreach ($names as $locale => $n) {
            $name = trim(strip_tags($n['name']));
            $short = trim(strip_tags($n['short']));
            if ($name === '' && $short === '') {
                continue;
            }
            $name = mb_substr($name !== '' ? $name : $short, 0, 128, 'UTF-8');
            $short = mb_substr($short !== '' ? $short : $name, 0, 32, 'UTF-8');
            $clean[$locale] = [$name, $short];
        }
        if ($clean === []) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.units.err_name'));
        }
        $symbol = reset($clean)[1];
        $sort = 1000 + (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_measurement_unit WHERE code NOT IN ('" . implode("','", self::BUILTIN) . "')");
        $this->db->transactional(function (Connection $db) use ($code, $dimension, $decimalScale, $clean, $symbol, $sort): void {
            $db->insert('mc_measurement_unit', [
                'code' => $code, 'dimension' => $dimension, 'symbol' => $symbol, 'unece_code' => null, 'google_unit_code' => null,
                'factor_to_si' => null, 'decimal_scale' => max(0, min(6, $decimalScale)), 'enabled' => 1, 'sort_order' => min(65000, $sort),
            ]);
            foreach ($clean as $locale => [$name, $short]) {
                $db->insert('mc_measurement_unit_translation', ['unit_code' => $code, 'locale' => $locale, 'name' => $name, 'short_name' => $short]);
            }
        });
        $this->cache = [];

        return $code;
    }

    public function setEnabled(string $code, bool $enabled): void
    {
        if (!$enabled && $code === 'item') {
            throw new \DomainException(CanonicalUiText::get('admin.units.err_default'));
        }
        $this->db->update('mc_measurement_unit', ['enabled' => $enabled ? 1 : 0], ['code' => $code]);
        $this->cache = [];
    }

    public function delete(string $code): void
    {
        if (in_array($code, self::BUILTIN, true)) {
            throw new \DomainException(CanonicalUiText::get('admin.units.err_builtin'));
        }
        $used = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_variant WHERE sale_unit_code=? OR unit_pricing_measure_code=? OR unit_pricing_base_code=?', [$code, $code, $code])
            + (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_inventory_item WHERE unit_code=?', [$code]);
        if ($used > 0) {
            throw new \DomainException(CanonicalUiText::get('admin.units.err_used'));
        }
        $this->db->delete('mc_measurement_unit', ['code' => $code]);
        $this->cache = [];
    }

    private function slug(string $text): string
    {
        $t = (string) preg_replace('/[^a-z0-9]+/', '_', strtolower((new \Commerce\Modules\Seo\Application\UkrainianTransliterator())->transliterate($text)));

        return trim(substr($t, 0, 32), '_');
    }
}
