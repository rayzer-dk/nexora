<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Core\Configuration\ConfigurationRevisionStore;
use Commerce\Modules\Shipping\Application\ShippingCountryService;
use Commerce\Modules\Seo\System\SystemPageRouteCatalog;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Store-scoped, opt-in merchant structured-data policies.
 * Does not change checkout shipping prices, customer rights, or Merchant Center settings.
 */
final class MerchantSchemaSettings
{
    /** @var array<int,array<string,mixed>> */
    private array $cached = [];

    public function __construct(
        private readonly ConfigurationRevisionStore $revisions,
        private readonly Connection $db,
        private readonly SystemPageRouteCatalog $systemRoutes,
    ) {
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'shipping_enabled' => false,
            'shipping_name' => '',
            'shipping_countries' => '',
            'shipping_currency' => '',
            'shipping_rate' => '',
            'handling_min' => '',
            'handling_max' => '',
            'transit_min' => '',
            'transit_max' => '',
            'shipping_on_offers' => false,
            'returns_enabled' => false,
            'return_countries' => '',
            'return_category' => 'finite',
            'return_days' => '',
            'return_method' => '',
            'return_fees' => '',
            'return_cost' => '',
            'return_currency' => '',
        ];
    }

    /** @return array<string,mixed> */
    public function get(int $storeId): array
    {
        if (isset($this->cached[$storeId])) {
            return $this->cached[$storeId];
        }
        $raw = $this->revisions->latestValidPayload($storeId, 'seo', 'merchant_schema');
        if (!is_array($raw)) {
            return $this->cached[$storeId] = self::defaults();
        }

        try {
            return $this->cached[$storeId] = $this->normalize(array_replace(self::defaults(), $raw));
        } catch (InvalidArgumentException) {
            // Invalid legacy payload must never publish a shipping or returns claim.
            return $this->cached[$storeId] = self::defaults();
        }
    }

    /** @param array<string,mixed> $input */
    public function save(int $storeId, array $input, string $actor = 'admin'): int
    {
        $revision = $this->revisions->activateStoreJson($storeId, 'seo', 'merchant_schema', $this->normalize($input), $actor);
        unset($this->cached[$storeId]);
        return $revision;
    }

    public function enabled(int $storeId): bool
    {
        return $this->get($storeId)['enabled'] === true;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    public function normalize(array $values): array
    {
        $input = array_replace(self::defaults(), $values);
        $v = [];
        foreach (['enabled', 'shipping_enabled', 'shipping_on_offers', 'returns_enabled'] as $key) {
            $v[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['shipping_name'] as $key) {
            $v[$key] = mb_substr(trim(strip_tags((string) $input[$key])), 0, 160, 'UTF-8');
        }
        foreach (['shipping_countries', 'return_countries'] as $key) {
            $codes = preg_split('/[\s,;]+/', strtoupper(trim((string) $input[$key])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $codes = array_values(array_unique($codes));
            $allowed = ShippingCountryService::allCountryCodes();
            if (count($codes) > 50 || array_diff($codes, $allowed) !== []) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.countries'));
            }
            $v[$key] = implode(', ', $codes);
        }
        foreach (['shipping_currency', 'return_currency'] as $key) {
            $v[$key] = strtoupper(trim((string) $input[$key]));
            if ($v[$key] !== '' && preg_match('/^[A-Z]{3}$/D', $v[$key]) !== 1) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.currency'));
            }
        }
        foreach (['shipping_rate', 'return_cost'] as $key) {
            $v[$key] = trim((string) $input[$key]);
            if ($v[$key] !== '' && preg_match('/^\d{1,8}(?:\.\d{1,4})?$/D', $v[$key]) !== 1) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.amount'));
            }
        }
        foreach (['handling_min', 'handling_max', 'transit_min', 'transit_max', 'return_days'] as $key) {
            $v[$key] = trim((string) $input[$key]);
            if ($v[$key] !== '' && preg_match('/^\d{1,4}$/D', $v[$key]) !== 1) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.days'));
            }
        }
        $v['return_category'] = (string) $input['return_category'];
        if (!in_array($v['return_category'], ['finite', 'unlimited', 'not_permitted'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.return_category'));
        }
        $v['return_method'] = (string) $input['return_method'];
        if (!in_array($v['return_method'], ['', 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.return_method'));
        }
        $v['return_fees'] = (string) $input['return_fees'];
        if (!in_array($v['return_fees'], ['', 'FreeReturn', 'ReturnFeesCustomerResponsibility', 'ReturnShippingFees'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.return_fees'));
        }
        if ($v['shipping_enabled']) {
            if ($v['shipping_countries'] === '' || $v['shipping_currency'] === '' || $v['shipping_rate'] === '') {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.shipping_required'));
            }
            foreach (['handling', 'transit'] as $key) {
                if ($v[$key . '_min'] === '' || $v[$key . '_max'] === '' || (int) $v[$key . '_min'] > (int) $v[$key . '_max']) {
                    throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.shipping_time'));
                }
            }
        }
        if ($v['shipping_on_offers'] && !$v['shipping_enabled']) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.shipping_offers'));
        }
        if ($v['returns_enabled']) {
            if ($v['return_countries'] === '') {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.return_countries'));
            }
            if ($v['return_category'] === 'finite' && ($v['return_days'] === '' || (int) $v['return_days'] < 1)) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.return_days'));
            }
            if ($v['return_category'] !== 'not_permitted' && $v['return_fees'] === 'ReturnShippingFees'
                && ($v['return_cost'] === '' || $v['return_currency'] === '')) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('admin.merchant.validation.return_cost'));
            }
        }

        return $v;
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    public function enrichOrganization(array $node, int $storeId, string $base, string $locale = 'uk-UA'): array
    {
        if (!$this->enabled($storeId)) {
            return $node;
        }
        try {
            $profile = $this->db->fetchAssociative('SELECT legal_name,vat_number FROM mc_store_profile WHERE store_id=? LIMIT 1', [$storeId]);
            if (is_array($profile)) {
                if (trim((string) ($profile['legal_name'] ?? '')) !== '') {
                    $node['legalName'] = (string) $profile['legal_name'];
                }
                if (trim((string) ($profile['vat_number'] ?? '')) !== '') {
                    $node['vatID'] = (string) $profile['vat_number'];
                }
            }
        } catch (\Throwable) {
            // Optional merchant metadata must not break the storefront.
        }

        $shipping = $this->shippingService($storeId, $base);
        if ($shipping !== null) {
            $shippingUrl = $this->publishedSystemPageUrl($storeId, 'delivery', $base, $locale);
            if ($shippingUrl !== '') {
                $shipping['url'] = $shippingUrl;
            }
            $node['hasShippingService'] = [$shipping];
        }
        $returns = $this->returnPolicy($storeId);
        if ($returns !== null) {
            $returnUrl = $this->publishedSystemPageUrl($storeId, 'returns', $base, $locale);
            if ($returnUrl !== '') {
                $returns['merchantReturnLink'] = $returnUrl;
            }
            $node['hasMerchantReturnPolicy'] = $returns;
        }
        return $node;
    }

    /** @return array<string,mixed>|null */
    public function shippingService(int $storeId, string $base): ?array
    {
        $s = $this->get($storeId);
        if (!$s['enabled'] || !$s['shipping_enabled']) {
            return null;
        }
        return [
            '@type' => 'ShippingService',
            '@id' => rtrim($base, '/') . '/#shipping-service',
            'name' => $s['shipping_name'] !== '' ? $s['shipping_name'] : 'Standard delivery',
            'fulfillmentType' => 'https://schema.org/FulfillmentTypeDelivery',
            'handlingTime' => ['@type' => 'ServicePeriod', 'duration' => self::duration($s, 'handling')],
            'shippingConditions' => [[
                '@type' => 'ShippingConditions',
                'shippingDestination' => self::regions($s['shipping_countries']),
                'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => (float) $s['shipping_rate'], 'currency' => $s['shipping_currency']],
                'transitTime' => ['@type' => 'ServicePeriod', 'duration' => self::duration($s, 'transit')],
            ]],
        ];
    }

    /** @return array<string,mixed>|null */
    public function offerShipping(int $storeId, string $offerCurrency, bool $physical): ?array
    {
        $s = $this->get($storeId);
        if (!$physical || !$s['enabled'] || !$s['shipping_enabled'] || !$s['shipping_on_offers']
            || strtoupper($offerCurrency) !== $s['shipping_currency']) {
            return null;
        }
        return [
            '@type' => 'OfferShippingDetails',
            'shippingDestination' => self::regions($s['shipping_countries']),
            'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => (float) $s['shipping_rate'], 'currency' => $s['shipping_currency']],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => self::duration($s, 'handling'),
                'transitTime' => self::duration($s, 'transit'),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function returnPolicy(int $storeId): ?array
    {
        $s = $this->get($storeId);
        if (!$s['enabled'] || !$s['returns_enabled']) {
            return null;
        }
        $type = [
            'finite' => 'MerchantReturnFiniteReturnWindow',
            'unlimited' => 'MerchantReturnUnlimitedWindow',
            'not_permitted' => 'MerchantReturnNotPermitted',
        ][$s['return_category']];
        $policy = [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => explode(', ', $s['return_countries']),
            'returnPolicyCategory' => 'https://schema.org/' . $type,
        ];
        if ($s['return_category'] === 'finite') {
            $policy['merchantReturnDays'] = (int) $s['return_days'];
        }
        if ($s['return_category'] !== 'not_permitted') {
            if ($s['return_method'] !== '') {
                $policy['returnMethod'] = 'https://schema.org/' . $s['return_method'];
            }
            if ($s['return_fees'] !== '') {
                $policy['returnFees'] = 'https://schema.org/' . $s['return_fees'];
                if ($s['return_fees'] === 'ReturnShippingFees') {
                    $policy['returnShippingFeesAmount'] = [
                        '@type' => 'MonetaryAmount',
                        'value' => (float) $s['return_cost'],
                        'currency' => $s['return_currency'],
                    ];
                }
            }
        }
        return $policy;
    }

    /** Link to the policy only when published and translated for this store. */
    private function publishedSystemPageUrl(int $storeId, string $key, string $base, string $locale): string
    {
        try {
            $row = $this->db->fetchAssociative(
                "SELECT ce.status,ct.body_html,COALESCE(pm.noindex,0) AS noindex
                 FROM mc_content_entry ce
                 JOIN mc_content_translation ct ON ct.content_id=ce.id AND ct.locale=?
                 LEFT JOIN mc_content_page_meta pm ON pm.content_id=ce.id
                 WHERE ce.store_id=? AND ce.content_type='page' AND ce.system_key=? LIMIT 1",
                [$locale, $storeId, $key],
            );
            if (!is_array($row) || (string) $row['status'] !== 'published'
                || trim((string) ($row['body_html'] ?? '')) === ''
                || (int) ($row['noindex'] ?? 0) !== 0) {
                return '';
            }

            return rtrim($base, '/') . '/' . ltrim($this->systemRoutes->route($key, $locale)->path, '/');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string,mixed> $data */
    private static function duration(array $data, string $name): array
    {
        return ['@type' => 'QuantitativeValue', 'minValue' => (int) $data[$name . '_min'],
            'maxValue' => (int) $data[$name . '_max'], 'unitCode' => 'DAY'];
    }

    /** @return list<array<string,string>> */
    private static function regions(string $codes): array
    {
        return array_map(static fn (string $code): array => ['@type' => 'DefinedRegion', 'addressCountry' => $code], explode(', ', $codes));
    }
}
