<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Application;

use Commerce\Core\Configuration\SystemSettingStore;

/**
 * Which delivery and payment methods a store offers at checkout (Admin → Shipping → Delivery and payment methods).
 * Stored as one JSON document per store in the key/value settings store; a method that was never configured is ON,
 * so existing shops keep their behaviour after an upgrade.
 */
final class CheckoutMethodSettings
{
    public const DELIVERY = ['nova_post', 'ukrposhta', 'meest', 'delivery_auto', 'self_pickup'];
    public const PAYMENT = ['cash_on_delivery', 'bank_transfer'];

    /** @var array<int,array<string,bool>> */
    private array $cache = [];

    public function __construct(private readonly SystemSettingStore $store)
    {
    }

    /** @return array<string,bool> method code => enabled (only the switchable methods) */
    public function all(int $storeId): array
    {
        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }
        $stored = $this->store->getArray($this->key($storeId)) ?? [];
        $out = [];
        foreach ([...self::DELIVERY, ...self::PAYMENT] as $code) {
            $out[$code] = !array_key_exists($code, $stored) || (bool) $stored[$code];
        }

        return $this->cache[$storeId] = $out;
    }

    /** Methods that cannot be switched here (online acquiring, B2B invoice, digital delivery) are always allowed. */
    public function isEnabled(int $storeId, string $code): bool
    {
        return $this->all($storeId)[$code] ?? true;
    }

    /**
     * Prices and limits of every method: fee_minor, free_over_minor (0 = never free), min_minor / max_minor (0 = no limit of the order amount
     * after the discount), sort, and the title/text shown at checkout per language (empty = the built-in translation).
     *
     * @return array<string,array{fee_minor:int,free_over_minor:int,min_minor:int,max_minor:int,sort:int,title:array<string,string>,text:array<string,string>}>
     */
    public function config(int $storeId): array
    {
        $stored = $this->store->getArray($this->key($storeId)) ?? [];
        $raw = is_array($stored['_config'] ?? null) ? $stored['_config'] : [];
        $out = [];
        foreach ([...self::DELIVERY, ...self::PAYMENT] as $code) {
            $c = is_array($raw[$code] ?? null) ? $raw[$code] : [];
            $out[$code] = [
                'fee_minor' => max(0, (int) ($c['fee_minor'] ?? 0)), 'free_over_minor' => max(0, (int) ($c['free_over_minor'] ?? 0)),
                'min_minor' => max(0, (int) ($c['min_minor'] ?? 0)), 'max_minor' => max(0, (int) ($c['max_minor'] ?? 0)),
                'sort' => (int) ($c['sort'] ?? 0),
                'title' => self::texts($c['title'] ?? []), 'text' => self::texts($c['text'] ?? []),
            ];
        }

        return $out;
    }

    /** The fee of a delivery or payment method for an order of this amount (after the discount); free above the "free over" amount. */
    public function fee(int $storeId, string $code, int $amountMinor): int
    {
        $c = $this->config($storeId)[$code] ?? null;
        if ($c === null || $c['fee_minor'] === 0) {
            return 0;
        }

        return $c['free_over_minor'] > 0 && $amountMinor >= $c['free_over_minor'] ? 0 : $c['fee_minor'];
    }

    /** @return 'min'|'max'|null which limit the order amount breaks for this method */
    public function limit(int $storeId, string $code, int $amountMinor): ?string
    {
        $c = $this->config($storeId)[$code] ?? null;
        if ($c === null) {
            return null;
        }
        if ($c['min_minor'] > 0 && $amountMinor < $c['min_minor']) {
            return 'min';
        }

        return $c['max_minor'] > 0 && $amountMinor > $c['max_minor'] ? 'max' : null;
    }

    /** The owner's title or text of a method in a language; '' when none was written. */
    public function label(int $storeId, string $code, string $field, string $locale): string
    {
        $map = $this->config($storeId)[$code][$field] ?? [];

        return (string) ($map[$locale] ?? $map[substr($locale, 0, 2)] ?? '');
    }

    /** @param array<string,mixed> $methods code => {fee, free_over, min, max, sort, title[locale], text[locale]} in whole currency units */
    public function saveConfig(int $storeId, array $methods): void
    {
        $stored = $this->store->getArray($this->key($storeId)) ?? [];
        $raw = [];
        foreach ([...self::DELIVERY, ...self::PAYMENT] as $code) {
            $m = is_array($methods[$code] ?? null) ? $methods[$code] : [];
            $money = static fn (mixed $v): int => max(0, (int) round(((float) str_replace(',', '.', (string) $v)) * 100));
            $raw[$code] = [
                'fee_minor' => $money($m['fee'] ?? 0), 'free_over_minor' => $money($m['free_over'] ?? 0),
                'min_minor' => $money($m['min'] ?? 0), 'max_minor' => $money($m['max'] ?? 0),
                'sort' => (int) ($m['sort'] ?? 0),
                'title' => self::texts($m['title'] ?? []), 'text' => self::texts($m['text'] ?? []),
            ];
        }
        $stored['_config'] = $raw;
        if (!$this->store->setArray($this->key($storeId), $stored)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
        }
        unset($this->cache[$storeId]);
    }

    /** @return array<string,string> */
    private static function texts(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $locale => $text) {
            $text = mb_substr(trim(strip_tags((string) $text)), 0, 240);
            if ($text !== '' && preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', (string) $locale) === 1) {
                $out[(string) $locale] = $text;
            }
        }

        return $out;
    }

    /** @param list<string> $enabledCodes codes ticked in the admin form */
    public function save(int $storeId, array $enabledCodes): void
    {
        $doc = $this->store->getArray($this->key($storeId)) ?? [];
        foreach ([...self::DELIVERY, ...self::PAYMENT] as $code) {
            $doc[$code] = in_array($code, $enabledCodes, true);
        }
        if (!$this->store->setArray($this->key($storeId), $doc)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
        }
        unset($this->cache[$storeId]);
    }

    private function key(int $storeId): string
    {
        return 'checkout.methods.' . $storeId;
    }
}
