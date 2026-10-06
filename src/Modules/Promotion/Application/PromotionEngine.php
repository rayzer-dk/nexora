<?php

declare(strict_types=1);

namespace Commerce\Modules\Promotion\Application;

use Commerce\Modules\Promotion\Domain\PromotionResult;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class PromotionEngine
{
    public function __construct(private Connection $db) {}

    public function calculateForCart(int $storeId, int $cartId, ?string $couponCode = null, ?int $customerId = null, ?string $email = null, bool $forUpdate = false): PromotionResult
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $items = $this->db->fetchAllAssociative(
            "SELECT ci.variant_id,v.product_id,ci.quantity,ci.unit_price_minor
             FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id
             WHERE ci.cart_id=? ORDER BY ci.id" . $lock,
            [$cartId],
        );
        $subtotal = 0;
        $productIds = [];
        foreach ($items as $item) {
            $quantityMicros = (int) round(((float) $item['quantity']) * 1000000);
            $subtotal += intdiv(((int) $item['unit_price_minor'] * $quantityMicros) + 500000, 1000000);
            $productIds[(int) $item['product_id']] = true;
        }
        if ($subtotal <= 0 || $items === []) return new PromotionResult($subtotal, 0, $subtotal, []);

        $categoryIds = [];
        if ($productIds !== []) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT product_id,category_id FROM mc_product_category WHERE product_id IN (?)',
                [array_keys($productIds)],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) $categoryIds[(int) $row['category_id']] = true;
        }

        $normalizedCode = $couponCode !== null ? mb_strtoupper(trim($couponCode)) : null;
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $params = [$storeId, $now, $now];
        $sql = "SELECT * FROM mc_promotion WHERE store_id=? AND status='active'
                AND (starts_at IS NULL OR starts_at<=?) AND (ends_at IS NULL OR ends_at>=?)
                AND (usage_limit IS NULL OR usage_count < usage_limit)
                ORDER BY priority ASC,id ASC" . ($forUpdate ? ' FOR UPDATE' : '');
        $promotions = $this->db->fetchAllAssociative($sql, $params);
        $cartCurrency = (string) $this->db->fetchOne('SELECT currency FROM mc_cart WHERE id=? LIMIT 1', [$cartId]);
        $discount = 0; $applied = []; $couponMatched = false;
        $customerGroup = $customerId !== null ? (string)($this->db->fetchOne('SELECT customer_group_code FROM mc_customer WHERE id=? LIMIT 1',[$customerId]) ?: 'default') : 'guest';
        $groupRow = $customerId !== null ? $this->db->fetchAssociative("SELECT g.discount_bps,g.name,g.skip_sale_items FROM mc_customer_group g WHERE g.code=? LIMIT 1", [$customerGroup]) : false;
        if (is_array($groupRow) && (int) $groupRow['discount_bps'] > 0 && $subtotal > 0) {
            $groupBase = $subtotal;
            if ((int) $groupRow['skip_sale_items'] === 1) {
                foreach ($items as $item) {
                    $onSale = $this->db->fetchOne("SELECT 1 FROM mc_price WHERE variant_id=? AND customer_group='default' AND price_list_id IS NULL AND compare_at_minor>amount_minor AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP(6)) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6)) LIMIT 1", [(int) $item['variant_id']]);
                    if ($onSale) {
                        $groupBase -= intdiv(((int) $item['unit_price_minor'] * (int) round(((float) $item['quantity']) * 1000000)) + 500000, 1000000);
                    }
                }
            }
            $groupAmount = intdiv(max(0, $groupBase) * min(9000, (int) $groupRow['discount_bps']), 10000);
            if ($groupAmount > 0) {
                $discount += $groupAmount;
                $applied[] = ['id'=>0,'name'=>(string)$groupRow['name'],'code'=>null,'discount_minor'=>$groupAmount];
            }
        }
        $emailNormalized = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;

        foreach ($promotions as $promotion) {
            $trigger = (string) $promotion['trigger_type'];
            $code = $promotion['code'] !== null ? mb_strtoupper(trim((string) $promotion['code'])) : null;
            if ($trigger === 'coupon') {
                if ($normalizedCode === null || $normalizedCode === '' || $code === null || !hash_equals($code, $normalizedCode)) continue;
                $couponMatched = true;
            } elseif ($trigger !== 'automatic') {
                continue;
            }
            if ($subtotal < (int) $promotion['min_subtotal_minor']) continue;
            if (!$this->conditionsMatch($promotion['conditions_json'] ?? null, array_keys($productIds), array_keys($categoryIds), $customerGroup, $cartCurrency, $storeId)) continue;
            if (!$this->withinCustomerLimit((int) $promotion['id'], $promotion['per_customer_limit'], $customerId, $emailNormalized)) continue;

            $base = max(0, $subtotal - $discount);
            $value = (int) $promotion['discount_value'];
            $amount = match ((string) $promotion['discount_type']) {
                'percent' => intdiv($base * min(10000, $value), 10000),
                'fixed' => min($base, $value),
                default => 0,
            };
            if ($promotion['max_discount_minor'] !== null) $amount = min($amount, (int) $promotion['max_discount_minor']);
            if ($amount <= 0) continue;
            $discount += min($amount, max(0, $subtotal - $discount));
            $applied[] = ['id'=>(int)$promotion['id'],'name'=>(string)$promotion['name'],'code'=>$code,'discount_minor'=>$amount];
            if ((int) $promotion['stop_processing'] === 1 || $discount >= $subtotal) break;
        }

        $message = null;
        if ($normalizedCode !== null && $normalizedCode !== '' && !$couponMatched) $message = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.promotion.application.promotionengine.promokod_ne_znaideno_abo_vin_uzhe_neaktyvnyi');
        elseif ($normalizedCode !== null && $normalizedCode !== '' && $couponMatched && !array_filter($applied, static fn(array $x): bool => $x['code'] === $normalizedCode)) $message = \Commerce\Core\I18n\CanonicalUiText::get('php.modules.promotion.application.promotionengine.umovy_tsoho_promokodu_dlia_potochnoho_koshyka_ne_vyk');

        return new PromotionResult($subtotal, $discount, max(0, $subtotal - $discount), $applied, $message);
    }

    /** @param list<int> $productIds @param list<int> $categoryIds */
    private function conditionsMatch(mixed $json, array $productIds, array $categoryIds, string $customerGroup, string $cartCurrency = '', int $storeId = 0): bool
    {
        if ($json === null || $json === '') return true;
        try { $conditions = is_array($json) ? $json : json_decode((string) $json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return false; }
        if (!is_array($conditions)) return false;
        $requiredProducts = array_values(array_filter(array_map('intval', is_array($conditions['product_ids'] ?? null) ? $conditions['product_ids'] : [])));
        $requiredCategories = array_values(array_filter(array_map('intval', is_array($conditions['category_ids'] ?? null) ? $conditions['category_ids'] : [])));
        if ($requiredProducts !== [] && array_intersect($requiredProducts, $productIds) === []) return false;
        if ($requiredCategories !== [] && array_intersect($requiredCategories, $categoryIds) === []) return false;
        $groups = array_values(array_filter(array_map(static fn($v): string => strtolower(trim((string)$v)), is_array($conditions['customer_groups'] ?? null) ? $conditions['customer_groups'] : [])));
        if ($groups !== [] && !in_array(strtolower($customerGroup), $groups, true)) return false;
        $marketIds = array_values(array_filter(array_map('intval', is_array($conditions['market_ids'] ?? null) ? $conditions['market_ids'] : [])));
        if ($marketIds !== []) {
            // A cart is priced in the currency of its market, so a market condition matches by that currency.
            $currencies = $this->db->fetchFirstColumn('SELECT default_currency FROM mc_market WHERE store_id=? AND id IN (?)', [$storeId, $marketIds], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]);
            if (!in_array($cartCurrency, array_map('strval', $currencies), true)) return false;
        }
        return true;
    }

    private function withinCustomerLimit(int $promotionId, mixed $limit, ?int $customerId, ?string $email): bool
    {
        if ($limit === null || (int) $limit <= 0) return true;
        if ($customerId === null && $email === null) return true;
        $count = $customerId !== null
            ? (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_promotion_redemption WHERE promotion_id=? AND customer_id=?', [$promotionId,$customerId])
            : (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_promotion_redemption WHERE promotion_id=? AND email_normalized=?', [$promotionId,$email]);
        return $count < (int) $limit;
    }
}
