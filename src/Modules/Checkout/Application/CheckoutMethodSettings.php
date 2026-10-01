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

    /** @param list<string> $enabledCodes codes ticked in the admin form */
    public function save(int $storeId, array $enabledCodes): void
    {
        $doc = [];
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
