<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Pricing\Application\CurrencyPriceSynchronizer;
use Commerce\Modules\Storefront\Infrastructure\StorefrontMoneyFormatter;
use Doctrine\DBAL\Connection;

/**
 * Options the shopper fills in on the product page: single choice (dropdown, radio buttons), multiple choice (checkboxes), text, date, time.
 * Each choice can add to the price, take from it or set it ("+", "-", "="), and change the weight. Amounts are kept in the store's main
 * currency and converted to the currency the shopper picked with the same rate as the converted product prices.
 */
final class ProductAddonService
{
    public const KINDS = ['select', 'radio', 'checkbox', 'text', 'textarea', 'date', 'time', 'datetime'];
    private const CHOICE = ['select', 'radio', 'checkbox'];
    private const MODES = ['add', 'sub', 'set'];

    public function __construct(
        private readonly Connection $db,
        private readonly PublicIdFactory $ids,
        private readonly CurrencyPriceSynchronizer $fx,
        private readonly StorefrontMoneyFormatter $money,
    ) {
    }

    public function hasAny(int $productId): bool
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_product_addon WHERE product_id=?', [$productId]) > 0;
    }

    /** @return list<array<string,mixed>> for the admin form: amounts in whole units of the main currency */
    public function forEdit(int $productId, string $locale): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT a.*,COALESCE(t.name,(SELECT x.name FROM mc_product_addon_translation x WHERE x.addon_id=a.id ORDER BY x.locale LIMIT 1),a.code) name FROM mc_product_addon a LEFT JOIN mc_product_addon_translation t ON t.addon_id=a.id AND t.locale=? WHERE a.product_id=? ORDER BY a.sort_order,a.id', [$locale, $productId]) as $a) {
            $values = [];
            foreach ($this->db->fetchAllAssociative('SELECT v.*,COALESCE(t.name,(SELECT x.name FROM mc_product_addon_value_translation x WHERE x.value_id=v.id ORDER BY x.locale LIMIT 1),CAST(v.id AS CHAR)) name FROM mc_product_addon_value v LEFT JOIN mc_product_addon_value_translation t ON t.value_id=v.id AND t.locale=? WHERE v.addon_id=? ORDER BY v.sort_order,v.id', [$locale, (int) $a['id']]) as $v) {
                $values[] = ['id' => (int) $v['id'], 'name' => (string) $v['name'], 'mode' => (string) $v['price_mode'], 'delta' => number_format(((int) $v['price_delta_minor']) / 100, 2, '.', ''), 'weight_g' => (int) $v['weight_delta_g'], 'default' => (bool) $v['is_default'], 'media_id' => $v['media_asset_id'] !== null ? (int) $v['media_asset_id'] : null];
            }
            $out[] = ['id' => (int) $a['id'], 'name' => (string) $a['name'], 'kind' => (string) $a['kind'], 'required' => (bool) $a['required'], 'mode' => (string) $a['price_mode'], 'delta' => number_format(((int) $a['price_delta_minor']) / 100, 2, '.', ''), 'weight_g' => (int) $a['weight_delta_g'], 'max_length' => $a['max_length'] !== null ? (int) $a['max_length'] : null, 'values' => $values];
        }

        return $out;
    }

    /**
     * @param array<int|string,mixed> $addons  addon id => name, kind, required, mode, delta, weight_g, max_length, new_value
     * @param array<int|string,mixed> $values  value id => name, mode, delta, weight_g, default
     * @param array<string,mixed>     $new     name, kind, values ("a, b, c")
     */
    public function save(int $productId, string $locale, array $addons, array $values, array $new): void
    {
        $this->db->transactional(function (Connection $db) use ($productId, $locale, $addons, $values, $new): void {
            $own = array_map('intval', $db->fetchFirstColumn('SELECT id FROM mc_product_addon WHERE product_id=?', [$productId]));
            foreach ($addons as $id => $data) {
                if (!in_array((int) $id, $own, true) || !is_array($data)) {
                    continue;
                }
                $name = $this->text((string) ($data['name'] ?? ''), 190);
                if ($name !== '') {
                    $this->translate($db, 'mc_product_addon_translation', 'addon_id', (int) $id, $locale, $name);
                }
                $kind = (string) ($data['kind'] ?? 'select');
                $update = ['kind' => in_array($kind, self::KINDS, true) ? $kind : 'select', 'required' => !empty($data['required']) ? 1 : 0, 'price_mode' => $this->mode($data['mode'] ?? 'add'), 'price_delta_minor' => abs($this->minor((string) ($data['delta'] ?? '0'))), 'weight_delta_g' => $this->grams($data['weight_g'] ?? 0)];
                $max = (int) ($data['max_length'] ?? 0);
                $update['max_length'] = $max > 0 ? min(2000, $max) : null;
                $db->update('mc_product_addon', $update, ['id' => (int) $id]);
                foreach ($this->split((string) ($data['new_value'] ?? '')) as $label) {
                    $this->insertValue($db, (int) $id, $locale, $label);
                }
            }
            $ownValues = array_map('intval', $db->fetchFirstColumn('SELECT v.id FROM mc_product_addon_value v JOIN mc_product_addon a ON a.id=v.addon_id WHERE a.product_id=?', [$productId]));
            foreach ($values as $id => $data) {
                if (!in_array((int) $id, $ownValues, true) || !is_array($data)) {
                    continue;
                }
                $name = $this->text((string) ($data['name'] ?? ''), 190);
                if ($name !== '') {
                    $this->translate($db, 'mc_product_addon_value_translation', 'value_id', (int) $id, $locale, $name);
                }
                $media = (int) ($data['media'] ?? 0);
                $db->update('mc_product_addon_value', ['price_mode' => $this->mode($data['mode'] ?? 'add'), 'price_delta_minor' => abs($this->minor((string) ($data['delta'] ?? '0'))), 'weight_delta_g' => $this->grams($data['weight_g'] ?? 0), 'is_default' => !empty($data['default']) ? 1 : 0, 'media_asset_id' => $media > 0 && (int) $db->fetchOne('SELECT COUNT(*) FROM mc_product_media WHERE product_id=? AND media_asset_id=?', [$productId, $media]) > 0 ? $media : null], ['id' => (int) $id]);
            }
            $name = $this->text((string) ($new['name'] ?? ''), 190);
            if ($name !== '') {
                $kind = (string) ($new['kind'] ?? 'select');
                $kind = in_array($kind, self::KINDS, true) ? $kind : 'select';
                $order = (int) $db->fetchOne('SELECT COALESCE(MAX(sort_order),0)+1 FROM mc_product_addon WHERE product_id=?', [$productId]);
                $db->insert('mc_product_addon', ['public_id' => $this->ids->binary(), 'product_id' => $productId, 'code' => 'a' . bin2hex(random_bytes(6)), 'kind' => $kind, 'sort_order' => $order]);
                $addonId = (int) $db->lastInsertId();
                $this->translate($db, 'mc_product_addon_translation', 'addon_id', $addonId, $locale, $name);
                if (in_array($kind, self::CHOICE, true)) {
                    foreach ($this->split((string) ($new['values'] ?? '')) as $label) {
                        $this->insertValue($db, $addonId, $locale, $label);
                    }
                }
            }
        });
    }

    public function deleteAddon(int $productId, int $addonId): void
    {
        $this->db->delete('mc_product_addon', ['id' => $addonId, 'product_id' => $productId]);
    }

    public function deleteValue(int $productId, int $valueId): void
    {
        $this->db->executeStatement('DELETE v FROM mc_product_addon_value v JOIN mc_product_addon a ON a.id=v.addon_id WHERE v.id=? AND a.product_id=?', [$valueId, $productId]);
    }

    /**
     * What the product page shows: every option with its choices, each with the price effect in the shopper's currency.
     *
     * @return list<array<string,mixed>>
     */
    public function forStorefront(int $productId, int $storeId, string $locale, string $currency): array
    {
        $out = [];
        foreach ($this->load($productId, $locale) as $a) {
            $item = ['id' => $a['id'], 'name' => $a['name'], 'kind' => $a['kind'], 'required' => $a['required'], 'max_length' => $a['max_length'], 'values' => []];
            $effect = $this->effect($storeId, $currency, $a['price_mode'], $a['price_delta_minor']);
            $item['price_label'] = $effect === null ? '' : $this->label($effect, $currency, $locale);
            $item['mode'] = $a['price_mode'];
            $item['amount_minor'] = $effect['amount'] ?? 0;
            $item['unavailable'] = $effect === null && $a['price_delta_minor'] > 0;
            foreach ($a['values'] as $v) {
                $e = $this->effect($storeId, $currency, $v['price_mode'], $v['price_delta_minor']);
                $item['values'][] = ['id' => $v['id'], 'name' => $v['name'], 'default' => $v['is_default'], 'mode' => $v['price_mode'], 'amount_minor' => $e['amount'] ?? 0, 'label' => $e === null ? '' : $this->label($e, $currency, $locale), 'unavailable' => $e === null && $v['price_delta_minor'] > 0, 'weight_g' => $v['weight_delta_g'], 'media_id' => $v['media_id']];
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Checks what the shopper sent and turns it into the choices kept in the cart, the price effect, the weight and a readable text.
     *
     * @param array<int|string,mixed> $input addon id => value id | list of value ids | text
     * @return array{selections:array<int,mixed>,hash:string,add_minor:int,set_minor:?int,weight_g:int,text:string}
     */
    public function resolve(int $productId, int $storeId, string $locale, string $currency, array $input, bool $enforceRequired = true): array
    {
        $selections = [];
        $add = 0;
        $set = null;
        $weight = 0;
        $parts = [];
        foreach ($this->load($productId, $locale) as $a) {
            $raw = $input[$a['id']] ?? $input[(string) $a['id']] ?? null;
            $chosen = [];
            $valueText = '';
            if (in_array($a['kind'], self::CHOICE, true)) {
                $ids = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
                if ($a['kind'] !== 'checkbox') {
                    $ids = array_slice($ids, 0, 1);
                }
                $names = [];
                foreach (array_unique(array_map('intval', $ids)) as $valueId) {
                    $found = null;
                    foreach ($a['values'] as $v) {
                        if ($v['id'] === $valueId) {
                            $found = $v;
                        }
                    }
                    if ($found === null) {
                        throw new \DomainException(CanonicalUiText::get('addon.error.invalid'));
                    }
                    $chosen[] = $valueId;
                    $names[] = $found['name'];
                    [$add, $set, $weight] = $this->apply($storeId, $currency, $found['price_mode'], $found['price_delta_minor'], $found['weight_delta_g'], $add, $set, $weight);
                }
                $valueText = implode(', ', $names);
                $selection = $chosen;
            } else {
                $text = is_string($raw) ? trim(strip_tags($raw)) : '';
                $text = $this->normalizeInput($a['kind'], $text, $a['max_length']);
                $valueText = $text;
                $selection = $text;
                if ($text !== '') {
                    [$add, $set, $weight] = $this->apply($storeId, $currency, $a['price_mode'], $a['price_delta_minor'], $a['weight_delta_g'], $add, $set, $weight);
                }
            }
            $empty = $selection === [] || $selection === '';
            if ($empty && $a['required'] && $enforceRequired) {
                throw new \DomainException(CanonicalUiText::get('addon.error.required', ['name' => $a['name']]));
            }
            if (!$empty) {
                $selections[$a['id']] = $selection;
                $parts[] = $a['name'] . ': ' . $valueText;
            }
        }
        ksort($selections);

        return ['selections' => $selections, 'hash' => $selections === [] ? '' : sha1((string) json_encode($selections)), 'add_minor' => $add, 'set_minor' => $set, 'weight_g' => $weight, 'text' => implode('; ', $parts)];
    }

    /** The unit price with the choices applied: "=" replaces the price, "+" and "-" change it. */
    public static function unitPrice(int $baseMinor, array $resolved): int
    {
        return max(0, (($resolved['set_minor'] ?? null) ?? $baseMinor) + (int) ($resolved['add_minor'] ?? 0));
    }

    /** The choices stored in a cart line, checked again against the product as it is now (a removed choice is dropped, the price follows the shopper's currency). */
    public function fromMetadata(int $productId, int $storeId, string $locale, string $currency, ?string $metadata): array
    {
        $data = $metadata !== null && $metadata !== '' ? json_decode($metadata, true) : null;
        $input = is_array($data) && is_array($data['addons'] ?? null) ? $data['addons'] : [];
        try {
            return $this->resolve($productId, $storeId, $locale, $currency, $input, false);
        } catch (\DomainException) {
            return ['selections' => [], 'hash' => '', 'add_minor' => 0, 'set_minor' => null, 'weight_g' => 0, 'text' => ''];
        }
    }

    /** Extra weight in grams of the choices stored in a cart line (no price or currency involved). */
    public function weightG(int $productId, ?string $metadata): int
    {
        $data = $metadata !== null && $metadata !== '' ? json_decode($metadata, true) : null;
        $input = is_array($data) && is_array($data['addons'] ?? null) ? $data['addons'] : [];
        $grams = 0;
        foreach ($this->load($productId, 'en-US') as $a) {
            $raw = $input[$a['id']] ?? null;
            if (in_array($a['kind'], self::CHOICE, true)) {
                foreach ((array) $raw as $valueId) {
                    foreach ($a['values'] as $v) {
                        if ($v['id'] === (int) $valueId) {
                            $grams += $v['weight_delta_g'];
                        }
                    }
                }
            } elseif (is_string($raw) && $raw !== '') {
                $grams += $a['weight_delta_g'];
            }
        }

        return $grams;
    }

    /** @param array<int,mixed> $selections */
    public static function metadata(array $selections): ?string
    {
        return $selections === [] ? null : (string) json_encode(['addons' => $selections], JSON_UNESCAPED_UNICODE);
    }

    /** @return array{0:int,1:?int,2:int} */
    private function apply(int $storeId, string $currency, string $mode, int $amount, int $weightG, int $add, ?int $set, int $weight): array
    {
        $effect = $this->effect($storeId, $currency, $mode, $amount);
        if ($effect === null && $amount > 0) {
            throw new \DomainException(CanonicalUiText::get('addon.error.currency'));
        }
        if ($effect !== null) {
            if ($mode === 'set') {
                $set = $effect['amount'];
            } else {
                $add += $mode === 'sub' ? -$effect['amount'] : $effect['amount'];
            }
        }

        return [$add, $set, $weight + $weightG];
    }

    /** @return array{mode:string,amount:int}|null null when the amount cannot be converted to the currency */
    private function effect(int $storeId, string $currency, string $mode, int $amountMinor): ?array
    {
        $converted = $amountMinor === 0 ? 0 : $this->fx->convertAmount($storeId, $amountMinor, $currency);

        return $converted === null ? null : ['mode' => $mode, 'amount' => $converted];
    }

    /** @param array{mode:string,amount:int} $effect */
    private function label(array $effect, string $currency, string $locale): string
    {
        if ($effect['amount'] === 0) {
            return '';
        }
        $sign = $effect['mode'] === 'sub' ? '−' : ($effect['mode'] === 'set' ? '=' : '+');

        return $sign . $this->money->format($effect['amount'], $currency, $locale);
    }

    private function normalizeInput(string $kind, string $text, ?int $max): string
    {
        if ($text === '') {
            return '';
        }
        $limit = $max ?? ($kind === 'textarea' ? 1000 : 255);
        $ok = match ($kind) {
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $text) === 1 && \DateTimeImmutable::createFromFormat('!Y-m-d', $text) !== false,
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $text) === 1,
            'datetime' => preg_match('/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/D', $text) === 1,
            default => mb_strlen($text) <= $limit,
        };
        if (!$ok) {
            throw new \DomainException(CanonicalUiText::get('addon.error.invalid'));
        }

        return $text;
    }

    /** @return list<array<string,mixed>> */
    private function load(int $productId, string $locale): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT a.id,a.kind,a.required,a.price_mode,a.price_delta_minor,a.weight_delta_g,a.max_length,COALESCE(t.name,(SELECT x.name FROM mc_product_addon_translation x WHERE x.addon_id=a.id ORDER BY x.locale LIMIT 1),a.code) name FROM mc_product_addon a LEFT JOIN mc_product_addon_translation t ON t.addon_id=a.id AND t.locale=? WHERE a.product_id=? ORDER BY a.sort_order,a.id', [$locale, $productId]) as $a) {
            $values = [];
            foreach ($this->db->fetchAllAssociative('SELECT v.id,v.price_mode,v.price_delta_minor,v.weight_delta_g,v.is_default,v.media_asset_id,COALESCE(t.name,(SELECT x.name FROM mc_product_addon_value_translation x WHERE x.value_id=v.id ORDER BY x.locale LIMIT 1),CAST(v.id AS CHAR)) name FROM mc_product_addon_value v LEFT JOIN mc_product_addon_value_translation t ON t.value_id=v.id AND t.locale=? WHERE v.addon_id=? ORDER BY v.sort_order,v.id', [$locale, (int) $a['id']]) as $v) {
                $values[] = ['id' => (int) $v['id'], 'name' => (string) $v['name'], 'price_mode' => (string) $v['price_mode'], 'price_delta_minor' => (int) $v['price_delta_minor'], 'weight_delta_g' => (int) $v['weight_delta_g'], 'is_default' => (bool) $v['is_default'], 'media_id' => $v['media_asset_id'] !== null ? (int) $v['media_asset_id'] : null];
            }
            $kind = (string) $a['kind'];
            if (in_array($kind, self::CHOICE, true) && $values === []) {
                continue;
            }
            $out[] = ['id' => (int) $a['id'], 'name' => (string) $a['name'], 'kind' => $kind, 'required' => (bool) $a['required'], 'price_mode' => (string) $a['price_mode'], 'price_delta_minor' => (int) $a['price_delta_minor'], 'weight_delta_g' => (int) $a['weight_delta_g'], 'max_length' => $a['max_length'] !== null ? (int) $a['max_length'] : null, 'values' => $values];
        }

        return $out;
    }

    private function insertValue(Connection $db, int $addonId, string $locale, string $label): void
    {
        $order = (int) $db->fetchOne('SELECT COALESCE(MAX(sort_order),0)+1 FROM mc_product_addon_value WHERE addon_id=?', [$addonId]);
        $db->insert('mc_product_addon_value', ['addon_id' => $addonId, 'sort_order' => $order]);
        $this->translate($db, 'mc_product_addon_value_translation', 'value_id', (int) $db->lastInsertId(), $locale, $label);
    }

    private function translate(Connection $db, string $table, string $column, int $id, string $locale, string $name): void
    {
        $db->executeStatement("INSERT INTO {$table} ({$column},locale,name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)", [$id, $locale, $name]);
    }

    /** @return list<string> */
    private function split(string $csv): array
    {
        $labels = [];
        foreach (preg_split('/[,;\n]+/u', $csv) ?: [] as $part) {
            $label = $this->text($part, 190);
            if ($label !== '' && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return array_slice($labels, 0, 60);
    }

    private function text(string $value, int $max): string
    {
        return mb_substr(trim(strip_tags($value)), 0, $max);
    }

    private function mode(mixed $mode): string
    {
        return in_array((string) $mode, self::MODES, true) ? (string) $mode : 'add';
    }

    private function grams(mixed $value): int
    {
        return max(-1000000, min(1000000, (int) $value));
    }

    private function minor(string $value): int
    {
        $clean = str_replace([' ', ','], ['', '.'], trim($value));

        return is_numeric($clean) ? (int) round(((float) $clean) * 100) : 0;
    }
}
