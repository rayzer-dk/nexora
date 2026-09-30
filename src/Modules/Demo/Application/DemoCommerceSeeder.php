<?php

declare(strict_types=1);

namespace Commerce\Modules\Demo\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Seeds a complete, self-consistent commerce history for the demo storefront:
 * customers, orders with payments/fulfilment/shipments, returns, withdrawals,
 * product questions and reviews, inquiries, newsletter and campaigns, carts, promotions,
 * gift cards, loyalty ledger, one B2B company and 30 days of analytics.
 *
 * Every row is removable without a registry:
 *  - customers, subscribers, inquiries, withdrawals: e-mail domain "@demo.nexora.test";
 *  - orders (and everything hanging off them): order_number prefix "DEMO-";
 *  - promotions: code prefix "DEMO";
 *  - gift cards, carts, search log rows, analytics sessions: deterministic hash / token markers;
 *  - campaigns, B2B rows and analytics page rows: ids recorded in one metadata row.
 * Reviews and questions belong to demo products and disappear with them.
 *
 * Texts come from CanonicalUiText keys (demo.commerce.*), so the source stays ASCII.
 */
final readonly class DemoCommerceSeeder
{
    public const EMAIL_DOMAIN = 'demo.nexora.test';
    public const ORDER_PREFIX = 'DEMO-';
    public const PROMO_PREFIX = 'DEMO';
    private const META_KEY = 'commerce_seed';
    private const VISITOR_MARK = 'DEMO';
    private const SEARCH_TERMS = 14;
    private const GIFT_CARDS = 3;
    private const VAT_BPS = 2000;

    /** @var list<array{0:string,1:string,2:int}> name, email local part, registration days ago */
    private const CUSTOMERS = [
        ['Olena Kovalenko', 'olena.kovalenko', 88],
        ['Taras Shevchuk', 'taras.shevchuk', 80],
        ['Iryna Bondarenko', 'iryna.bondarenko', 72],
        ['Andrii Melnyk', 'andrii.melnyk', 64],
        ['Kateryna Tkachenko', 'kateryna.tkachenko', 56],
        ['Dmytro Lysenko', 'dmytro.lysenko', 48],
        ['Oksana Marchenko', 'oksana.marchenko', 40],
        ['Serhii Polishchuk', 'serhii.polishchuk', 36],
        ['Natalia Savchenko', 'natalia.savchenko', 32],
        ['Yurii Kravchenko', 'yurii.kravchenko', 30],
        ['Maryna Petrenko', 'maryna.petrenko', 22],
        ['Oleksandr Hrytsenko', 'oleksandr.hrytsenko', 18],
        ['Viktoria Romaniuk', 'viktoria.romaniuk', 9],
        ['Bohdan Zaitsev', 'bohdan.zaitsev', 3],
    ];

    /** @var list<array{0:string,1:string}> */
    private const GUESTS = [
        ['Volodymyr Zinchenko', 'volodymyr.zinchenko'],
        ['Halyna Dovzhenko', 'halyna.dovzhenko'],
        ['Roman Yaremenko', 'roman.yaremenko'],
        ['Liudmyla Boyko', 'liudmyla.boyko'],
    ];

    /** @var list<array{0:string,1:string,2:string,3:string}> city, region, point, carrier-agnostic zip-like label */
    private const CITIES = [
        ['Kyiv', 'Kyiv', 'Branch No. 12', '01001'],
        ['Lviv', 'Lviv Oblast', 'Branch No. 5', '79000'],
        ['Odesa', 'Odesa Oblast', 'Parcel locker No. 3011', '65000'],
        ['Kharkiv', 'Kharkiv Oblast', 'Branch No. 27', '61000'],
        ['Dnipro', 'Dnipropetrovsk Oblast', 'Branch No. 44', '49000'],
        ['Vinnytsia', 'Vinnytsia Oblast', 'Branch No. 2', '21000'],
        ['Poltava', 'Poltava Oblast', 'Parcel locker No. 1187', '36000'],
        ['Ivano-Frankivsk', 'Ivano-Frankivsk Oblast', 'Branch No. 9', '76000'],
    ];

    private const CARRIERS = [
        'nova_post' => ['Nova Post', 'pickup_point', 8000],
        'ukrposhta' => ['Ukrposhta', 'pickup_point', 6000],
        'meest' => ['Meest', 'courier', 9000],
        'delivery_auto' => ['Delivery Auto', 'pickup_point', 8500],
    ];

    /** source, medium, campaign */
    private const SOURCES = [
        'organic' => ['google', 'organic', ''],
        'direct' => ['direct', 'none', ''],
        'instagram' => ['instagram', 'social', ''],
        'cpc' => ['google', 'cpc', 'demo-spring-sale'],
        'facebook' => ['facebook', 'social', ''],
        'newsletter' => ['newsletter', 'email', 'spring-newsletter'],
    ];

    /**
     * days ago, hour offset, buyer (>=0 customer index, <0 guest -(n+1)), profile, payment method, carrier,
     * items [[product index, qty]], promotion code, traffic source, extras.
     *
     * @var list<array{0:int,1:int,2:int,3:string,4:string,5:string,6:list<array{0:int,1:int}>,7:?string,8:string,9:array<string,mixed>}>
     */
    private const ORDERS = [
        [58, 4, 0, 'delivered', 'liqpay', 'nova_post', [[3, 1], [25, 1]], 'DEMO10', 'organic', []],
        [55, 7, 1, 'delivered', 'cash_on_delivery', 'nova_post', [[9, 2]], 'DEMOWELCOME', 'direct', ['comment' => 1]],
        [52, 3, 2, 'delivered', 'monobank', 'ukrposhta', [[7, 1]], 'DEMOWELCOME', 'instagram', []],
        [50, 9, -1, 'delivered', 'wayforpay', 'nova_post', [[16, 1], [28, 1]], null, 'cpc', []],
        [47, 5, 3, 'refunded', 'liqpay', 'nova_post', [[4, 1]], null, 'direct', ['return' => 1]],
        [44, 2, 4, 'delivered', 'bank_transfer', 'meest', [[0, 1]], 'DEMOSPRING', 'newsletter', ['note' => 1]],
        [41, 8, 5, 'cancelled', 'wayforpay', 'nova_post', [[21, 1]], null, 'facebook', []],
        [38, 6, 6, 'delivered', 'monobank', 'nova_post', [[10, 1], [12, 1]], 'DEMOSPRING', 'organic', ['comment' => 2]],
        [35, 4, 7, 'delivered', 'cash_on_delivery', 'ukrposhta', [[26, 3]], null, 'direct', []],
        [33, 10, 0, 'delivered', 'liqpay', 'nova_post', [[22, 1]], null, 'organic', ['points' => 5000]],
        [30, 3, 8, 'partial_refund', 'monobank', 'nova_post', [[17, 1], [15, 1]], null, 'instagram', ['return' => 2]],
        [28, 6, 9, 'delivered', 'liqpay', 'meest', [[13, 1]], null, 'newsletter', []],
        [26, 5, 2, 'delivered', 'wayforpay', 'nova_post', [[5, 1]], 'DEMOSPRING', 'cpc', []],
        [24, 2, 1, 'delivered', 'monobank', 'nova_post', [[24, 1], [9, 1]], null, 'newsletter', ['gift' => [1, 40000]]],
        [22, 7, -2, 'delivered', 'cash_on_delivery', 'delivery_auto', [[2, 1]], 'DEMO10', 'direct', []],
        [20, 4, 10, 'delivered', 'b2b_invoice', 'nova_post', [[8, 4], [6, 3]], null, 'direct', ['b2b' => true]],
        [18, 9, 3, 'delivered', 'liqpay', 'ukrposhta', [[19, 1]], 'DEMO10', 'organic', ['return' => 3]],
        [16, 5, 11, 'exception', 'monobank', 'nova_post', [[11, 1]], null, 'instagram', []],
        [14, 3, 4, 'delivered', 'wayforpay', 'nova_post', [[20, 1]], null, 'cpc', ['return' => 4]],
        [12, 8, 7, 'delivered', 'monobank', 'nova_post', [[27, 1], [28, 1]], 'DEMOVIP', 'organic', ['gift' => [2, 30000], 'points' => 1000, 'withdrawal' => 2]],
        [10, 6, 0, 'shipped', 'liqpay', 'nova_post', [[1, 1]], 'DEMOVIP', 'direct', ['note' => 2]],
        [8, 4, 5, 'shipped', 'cash_on_delivery', 'ukrposhta', [[23, 1]], null, 'facebook', []],
        [7, 2, -3, 'cancelled', 'cash_on_delivery', 'nova_post', [[14, 1]], null, 'direct', []],
        [6, 7, 8, 'processing', 'wayforpay', 'nova_post', [[18, 1]], 'DEMO10', 'organic', ['withdrawal' => 1]],
        [5, 5, 6, 'processing', 'liqpay', 'nova_post', [[10, 1], [9, 1]], null, 'newsletter', ['comment' => 3]],
        [4, 3, 9, 'confirmed', 'cash_on_delivery', 'nova_post', [[16, 2]], null, 'instagram', []],
        [3, 6, 10, 'awaiting_payment', 'bank_transfer', 'nova_post', [[3, 2]], null, 'direct', []],
        [2, 4, 11, 'new', 'monobank', 'nova_post', [[25, 1], [29, 1], [26, 1]], null, 'organic', []],
        [1, 5, -4, 'new', 'cash_on_delivery', 'ukrposhta', [[7, 1]], null, 'direct', []],
        [0, 3, 1, 'new', 'liqpay', 'nova_post', [[4, 1]], null, 'cpc', []],
    ];

    /**
     * profile => order status, payment status, fulfilment status, payment row status, shipment status|null.
     *
     * @var array<string,array{0:string,1:string,2:string,3:string,4:?string}>
     */
    private const PROFILES = [
        'delivered' => ['completed', 'paid', 'delivered', 'paid', 'delivered'],
        'refunded' => ['refunded', 'refunded', 'delivered', 'refunded', 'delivered'],
        'partial_refund' => ['completed', 'partially_refunded', 'delivered', 'partially_refunded', 'delivered'],
        'shipped' => ['processing', 'paid', 'shipped', 'paid', 'in_transit'],
        'exception' => ['processing', 'paid', 'shipped', 'paid', 'exception'],
        'processing' => ['processing', 'paid', 'preparing', 'paid', null],
        'confirmed' => ['confirmed', 'pending', 'pending', 'pending', null],
        'awaiting_payment' => ['awaiting_payment', 'pending', 'unfulfilled', 'pending', null],
        'new' => ['placed', 'pending', 'unfulfilled', 'pending', null],
        'cancelled' => ['cancelled', 'expired', 'cancelled', 'expired', null],
    ];

    public function __construct(
        private PublicIdFactory $publicIds,
    ) {
    }

    /**
     * @param array{store_id:int,store_public_id:string,market_id:int,locale:string,currency:string} $ctx
     * @param list<int> $productIds
     * @return array<string,int>
     */
    public function install(Connection $db, array $ctx, array $productIds): array
    {
        $storeId = (int) $ctx['store_id'];
        $this->remove($db, $storeId, true);

        $counts = [
            'customers' => 0, 'orders' => 0, 'shipments' => 0, 'returns' => 0, 'withdrawals' => 0,
            'reviews' => 0, 'questions' => 0, 'inquiries' => 0, 'subscribers' => 0, 'campaigns' => 0,
            'promotions' => 0, 'gift_cards' => 0, 'loyalty_transactions' => 0, 'b2b_companies' => 0,
            'analytics_sessions' => 0, 'search_queries' => 0, 'carts' => 0,
        ];
        $products = $this->loadProducts($db, $ctx, $productIds);
        if ($products === []) {
            return $counts;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $loose = ['mc_marketing_campaign' => [], 'mc_b2b_company' => [], 'mc_b2b_price_list' => []];
        $pageRows = [];

        $customers = $this->seedCustomers($db, $ctx, $now);
        $counts['customers'] = count($customers);
        $promotions = [];
        $counts['promotions'] = $this->guarded('promotions', function () use ($db, $storeId, $now, &$promotions): int {
            $promotions = $this->seedPromotions($db, $storeId, $now);

            return count($promotions) - (isset($promotions['DEMO10']) ? 1 : 0);
        });
        $giftCards = [];
        $counts['gift_cards'] = $this->guarded('gift_cards', function () use ($db, $ctx, $now, &$giftCards): int {
            $giftCards = $this->seedGiftCards($db, $ctx, $now);
            return count($giftCards);
        });
        $b2b = null;
        $counts['b2b_companies'] = $this->guarded('b2b', function () use ($db, $ctx, $now, $customers, $products, &$b2b, &$loose): int {
            $b2b = $this->seedB2b($db, $ctx, $now, $customers, $products, $loose);
            return $b2b === null ? 0 : 1;
        });

        $orders = $this->seedOrders($db, $ctx, $now, $customers, $products, $promotions, $giftCards, $b2b, $counts);

        $counts['returns'] = $this->guarded('returns', fn (): int => $this->seedReturns($db, $ctx, $now, $orders));
        $counts['shipments'] = (int) $db->fetchOne('SELECT COUNT(*) FROM mc_shipment s JOIN mc_sales_order o ON o.id=s.order_id WHERE o.store_id=? AND o.order_number LIKE ?', [$storeId, self::ORDER_PREFIX . '%']);
        $counts['withdrawals'] = $this->guarded('withdrawals', fn (): int => $this->seedWithdrawals($db, $ctx, $now, $orders));
        $counts['reviews'] = $this->guarded('reviews', fn (): int => $this->seedReviews($db, $ctx, $now, $customers, $products));
        $counts['questions'] = $this->guarded('questions', fn (): int => $this->seedQuestions($db, $ctx, $now, $customers, $products));
        $counts['inquiries'] = $this->guarded('inquiries', fn (): int => $this->seedInquiries($db, $ctx, $now, $customers, $products));
        $counts['subscribers'] = $this->guarded('subscribers', fn (): int => $this->seedSubscribers($db, $ctx, $now, $customers));
        $counts['campaigns'] = $this->guarded('campaigns', function () use ($db, $ctx, $now, &$loose): int {
            return $this->seedCampaigns($db, $ctx, $now, $loose);
        });
        $counts['carts'] = $this->guarded('carts', fn (): int => $this->seedCarts($db, $ctx, $now, $orders, $customers, $products));
        $counts['analytics_sessions'] = $this->guarded('analytics', function () use ($db, $ctx, $now, $orders, $products, &$pageRows): int {
            return $this->seedAnalytics($db, $ctx, $now, $orders, $products, $pageRows);
        });
        $counts['search_queries'] = $this->guarded('search', fn (): int => $this->seedSearchLog($db, $ctx, $now));

        $db->insert('mc_entity_metadata', [
            'entity_type' => 'store',
            'entity_public_id' => $ctx['store_public_id'],
            'namespace' => $this->namespace($storeId),
            'meta_key' => self::META_KEY,
            'value_json' => json_encode(['loose' => $loose, 'pages' => $pageRows, 'counts' => $counts], JSON_THROW_ON_ERROR),
            'updated_at' => $this->fmt($now),
        ]);

        return $counts;
    }

    /**
     * Removes everything install() created. Safe to call repeatedly and on stores that never had commerce demo data.
     *
     * @param bool $keepBasePromotion keep the DEMO10 coupon owned by DemoSeeder (used when re-seeding on top of a fresh base demo)
     */
    public function remove(Connection $db, int $storeId, bool $keepBasePromotion = false): void
    {
        $sm = $db->createSchemaManager();
        $has = static fn (string ...$tables): bool => $sm->tablesExist($tables);
        $orderScope = 'o.store_id=? AND o.order_number LIKE ?';
        $orderArgs = [$storeId, self::ORDER_PREFIX . '%'];
        $like = '%@' . self::EMAIL_DOMAIN;

        $meta = [];
        $raw = $db->fetchOne('SELECT value_json FROM mc_entity_metadata WHERE namespace=? AND meta_key=? LIMIT 1', [$this->namespace($storeId), self::META_KEY]);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        if ($has('mc_return_request', 'mc_sales_order')) {
            $db->executeStatement('DELETE rr FROM mc_return_request rr JOIN mc_sales_order o ON o.id=rr.order_id WHERE ' . $orderScope, $orderArgs);
        }
        if ($has('mc_payment', 'mc_payment_refund', 'mc_sales_order')) {
            $db->executeStatement('DELETE pr FROM mc_payment_refund pr JOIN mc_payment p ON p.id=pr.payment_id JOIN mc_sales_order o ON o.id=p.order_id WHERE ' . $orderScope, $orderArgs);
            $db->executeStatement('DELETE p FROM mc_payment p JOIN mc_sales_order o ON o.id=p.order_id WHERE ' . $orderScope, $orderArgs);
        }
        if ($has('mc_sales_order')) {
            $db->executeStatement('DELETE o FROM mc_sales_order o WHERE ' . $orderScope, $orderArgs);
        }
        if ($has('mc_withdrawal_notice')) {
            $db->executeStatement('DELETE FROM mc_withdrawal_notice WHERE store_id=? AND email_normalized LIKE ?', [$storeId, $like]);
        }
        if ($has('mc_customer_inquiry')) {
            $db->executeStatement('DELETE FROM mc_customer_inquiry WHERE store_id=? AND email LIKE ?', [$storeId, $like]);
        }
        if ($has('mc_marketing_subscriber')) {
            $db->executeStatement('DELETE FROM mc_marketing_subscriber WHERE store_id=? AND email_normalized LIKE ?', [$storeId, $like]);
        }
        foreach (['mc_marketing_campaign', 'mc_b2b_price_list', 'mc_b2b_company'] as $table) {
            $ids = array_values(array_filter(array_map('intval', (array) ($meta['loose'][$table] ?? [])), static fn (int $id): bool => $id > 0));
            if ($ids !== [] && $has($table)) {
                $db->executeStatement('DELETE FROM ' . $table . ' WHERE store_id=? AND id IN (' . implode(',', $ids) . ')', [$storeId]);
            }
        }
        if ($has('mc_gift_card')) {
            $hashes = [];
            for ($i = 1; $i <= self::GIFT_CARDS; $i++) {
                $hashes[] = hash('sha256', 'DEMO-GIFT-' . $i);
            }
            $db->executeStatement('DELETE FROM mc_gift_card WHERE store_id=? AND code_hash IN (?,?,?)', [$storeId, ...$hashes]);
        }
        if ($has('mc_customer')) {
            $db->executeStatement('DELETE FROM mc_customer WHERE email_normalized LIKE ?', [$like]);
        }
        if ($has('mc_promotion')) {
            $db->executeStatement('DELETE FROM mc_promotion WHERE store_id=? AND code LIKE ? AND (code<>? OR ?=0)', [$storeId, self::PROMO_PREFIX . '%', 'DEMO10', $keepBasePromotion ? 1 : 0]);
            if ($keepBasePromotion && $has('mc_promotion_redemption')) {
                $db->executeStatement("UPDATE mc_promotion p SET p.usage_count=(SELECT COUNT(*) FROM mc_promotion_redemption r WHERE r.promotion_id=p.id) WHERE p.store_id=? AND p.code='DEMO10'", [$storeId]);
            }
        }
        if ($has('mc_cart')) {
            $db->executeStatement('DELETE FROM mc_cart WHERE store_id=? AND SUBSTRING(token_hash,1,4)=?', [$storeId, self::VISITOR_MARK]);
        }
        if ($has('mc_analytics_session')) {
            $db->executeStatement('DELETE FROM mc_analytics_session WHERE store_id=? AND SUBSTRING(visitor_hash,1,4)=?', [$storeId, self::VISITOR_MARK]);
        }
        if ($has('mc_analytics_page_daily')) {
            foreach ((array) ($meta['pages'] ?? []) as $day => $paths) {
                if (!is_string($day) || !is_array($paths) || $paths === []) {
                    continue;
                }
                foreach (array_chunk(array_values($paths), 200) as $chunk) {
                    $db->executeStatement(
                        'DELETE FROM mc_analytics_page_daily WHERE store_id=? AND day=? AND path IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                        [$storeId, $day, ...array_map('strval', $chunk)],
                    );
                }
            }
        }
        if ($has('mc_search_query_log')) {
            $hashes = [];
            for ($i = 1; $i <= self::SEARCH_TERMS; $i++) {
                $hashes[] = $this->searchHash($i);
            }
            $db->executeStatement('DELETE FROM mc_search_query_log WHERE store_id=? AND query_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')', [$storeId, ...$hashes]);
        }
        $db->executeStatement('DELETE FROM mc_entity_metadata WHERE namespace=? AND meta_key=?', [$this->namespace($storeId), self::META_KEY]);
    }

    /** @param callable():int $fn */
    private function guarded(string $section, callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (\Throwable $e) {
            error_log((string) json_encode(['event' => 'demo_commerce_section_failed', 'section' => $section, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return 0;
        }
    }

    /** @return list<array{product_id:int,variant_id:int,sku:string,name:string,price:int,path:string}> */
    private function loadProducts(Connection $db, array $ctx, array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $in = implode(',', $productIds);
        $rows = $db->fetchAllAssociative(
            'SELECT p.id product_id,v.id variant_id,v.sku,pt.name,pr.amount_minor price,
                    (SELECT r.path FROM mc_seo_route r WHERE r.store_id=pt.store_id AND r.locale=pt.locale AND r.entity_type=\'product\' AND r.entity_public_id=p.public_id LIMIT 1) path
             FROM mc_product p
             JOIN mc_product_variant v ON v.product_id=p.id
             JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=? AND pt.locale=?
             JOIN mc_price pr ON pr.variant_id=v.id AND pr.store_id=? AND pr.price_list_id IS NULL AND pr.customer_group=\'default\' AND pr.min_quantity<=1
             WHERE p.id IN (' . $in . ')
             ORDER BY p.id,v.sort_order,v.id,pr.priority,pr.id',
            [$ctx['store_id'], $ctx['locale'], $ctx['store_id']],
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['product_id'];
            if (isset($out[$id]) || (int) $row['price'] <= 0) {
                continue;
            }
            $out[$id] = [
                'product_id' => $id,
                'variant_id' => (int) $row['variant_id'],
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'price' => (int) $row['price'],
                'path' => is_string($row['path']) && $row['path'] !== '' ? '/' . ltrim($row['path'], '/') : '/',
            ];
        }

        return array_values($out);
    }

    /** @return list<array{id:int,name:string,email:string,phone:string,created:DateTimeImmutable}> */
    private function seedCustomers(Connection $db, array $ctx, DateTimeImmutable $now): array
    {
        $out = [];
        foreach (self::CUSTOMERS as $i => [$name, $local, $days]) {
            $created = $now->modify('-' . $days . ' days')->modify('-' . (($i * 37) % 600) . ' minutes');
            $email = $local . '@' . self::EMAIL_DOMAIN;
            $phone = sprintf('+38050%07d', 1200000 + $i * 137311);
            $seen = $created->modify('+' . min($days, 2 + ($i % 5)) . ' days');
            if ($seen > $now) {
                $seen = $now;
            }
            $db->insert('mc_customer', [
                'public_id' => $this->publicIds->binary(),
                'email' => $email,
                'email_normalized' => $email,
                'email_verified_at' => $this->fmt($created),
                'phone_e164' => $phone,
                'display_name' => $name,
                'locale' => $ctx['locale'],
                'password_hash' => null,
                'status' => 'active',
                'customer_group_code' => 'default',
                'created_at' => $this->fmt($created),
                'updated_at' => $this->fmt($seen),
                'last_seen_at' => $this->fmt($seen),
            ]);
            $id = (int) $db->lastInsertId();
            $db->insert('mc_store_customer', [
                'store_id' => $ctx['store_id'],
                'customer_id' => $id,
                'customer_group_code' => 'default',
                'created_at' => $this->fmt($created),
                'updated_at' => $this->fmt($created),
            ]);
            $out[] = ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'created' => $created];
        }

        return $out;
    }

    /** @return array<string,array{id:int,name:string,type:string,value:int,min:int,max:?int,limit:?int}> */
    private function seedPromotions(Connection $db, int $storeId, DateTimeImmutable $now): array
    {
        $out = [];
        $existing = $db->fetchAssociative("SELECT id,name,discount_type,discount_value,min_subtotal_minor,max_discount_minor,per_customer_limit FROM mc_promotion WHERE store_id=? AND code='DEMO10' LIMIT 1", [$storeId]);
        if (is_array($existing)) {
            $out['DEMO10'] = [
                'id' => (int) $existing['id'], 'name' => (string) $existing['name'], 'type' => (string) $existing['discount_type'],
                'value' => (int) $existing['discount_value'], 'min' => (int) $existing['min_subtotal_minor'],
                'max' => $existing['max_discount_minor'] === null ? null : (int) $existing['max_discount_minor'],
                'limit' => $existing['per_customer_limit'] === null ? null : (int) $existing['per_customer_limit'],
            ];
        }
        $defs = [
            ['DEMOWELCOME', 'demo.commerce.promo.welcome', 'active', 'fixed', 20000, 100000, null, 500, 1, 30, null, null],
            ['DEMOSPRING', 'demo.commerce.promo.spring', 'disabled', 'percent', 1500, 300000, 300000, 300, 2, 25, '-60 days', '-20 days'],
            ['DEMOVIP', 'demo.commerce.promo.vip', 'active', 'percent', 700, 0, null, null, null, 40, '-30 days', '+60 days'],
        ];
        $conditions = json_encode(['product_ids' => [], 'category_ids' => [], 'market_ids' => [], 'customer_groups' => []], JSON_THROW_ON_ERROR);
        foreach ($defs as [$code, $nameKey, $status, $type, $value, $min, $max, $usageLimit, $perCustomer, $priority, $starts, $ends]) {
            $name = CanonicalUiText::get($nameKey);
            $db->insert('mc_promotion', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'name' => $name, 'code' => $code,
                'status' => $status, 'trigger_type' => 'coupon', 'discount_type' => $type, 'discount_value' => $value,
                'min_subtotal_minor' => $min, 'max_discount_minor' => $max, 'usage_limit' => $usageLimit, 'usage_count' => 0,
                'per_customer_limit' => $perCustomer, 'priority' => $priority, 'stop_processing' => 0, 'conditions_json' => $conditions,
                'starts_at' => $starts === null ? null : $this->fmt($now->modify($starts)),
                'ends_at' => $ends === null ? null : $this->fmt($now->modify($ends)),
                'created_at' => $this->fmt($now->modify('-62 days')), 'updated_at' => $this->fmt($now),
            ]);
            $out[$code] = ['id' => (int) $db->lastInsertId(), 'name' => $name, 'type' => $type, 'value' => $value, 'min' => $min, 'max' => $max, 'limit' => $perCustomer];
        }

        return $out;
    }

    /** @return list<array{id:int,initial:int,balance:int,last4:string,currency:string}> */
    private function seedGiftCards(Connection $db, array $ctx, DateTimeImmutable $now): array
    {
        $out = [];
        foreach ([[1, 50000, 70], [2, 100000, 40], [3, 30000, 25]] as [$n, $amount, $days]) {
            $issued = $now->modify('-' . $days . ' days');
            $last4 = sprintf('%04d', 4200 + $n * 111);
            $db->insert('mc_gift_card', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'customer_id' => null,
                'code_hash' => hash('sha256', 'DEMO-GIFT-' . $n), 'code_last4' => $last4, 'currency' => $ctx['currency'],
                'initial_minor' => $amount, 'balance_minor' => $amount, 'status' => 'active',
                'expires_at' => $this->fmt($issued->modify('+1 year')), 'created_at' => $this->fmt($issued), 'updated_at' => $this->fmt($issued),
            ]);
            $id = (int) $db->lastInsertId();
            $db->insert('mc_gift_card_transaction', [
                'gift_card_id' => $id, 'order_id' => null, 'tx_type' => 'issue', 'amount_minor' => $amount,
                'balance_after_minor' => $amount, 'idempotency_key' => 'demo:gift:issue:' . $n, 'created_at' => $this->fmt($issued),
            ]);
            $out[$n] = ['id' => $id, 'initial' => $amount, 'balance' => $amount, 'last4' => $last4, 'currency' => $ctx['currency']];
        }

        return $out;
    }

    /**
     * @param list<array{id:int,name:string,email:string,phone:string,created:DateTimeImmutable}> $customers
     * @param list<array{product_id:int,variant_id:int,sku:string,name:string,price:int,path:string}> $products
     * @param array<string,list<int>> $loose
     * @return array{company_id:int,customer_id:int,name:string,tax_id:string,terms:int}|null
     */
    private function seedB2b(Connection $db, array $ctx, DateTimeImmutable $now, array $customers, array $products, array &$loose): ?array
    {
        $buyer = $customers[10] ?? null;
        if ($buyer === null) {
            return null;
        }
        $created = $now->modify('-24 days');
        $name = 'Nova Tech Trading';
        $taxId = '43918276';
        $db->insert('mc_b2b_company', [
            'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'name' => $name, 'legal_name' => 'Nova Tech Trading LLC',
            'tax_id' => $taxId, 'vat_id' => '439182726501', 'currency' => $ctx['currency'], 'status' => 'active',
            'credit_limit_minor' => 50000000, 'payment_terms_days' => 14, 'approval_threshold_minor' => 30000000,
            'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($created),
        ]);
        $companyId = (int) $db->lastInsertId();
        $loose['mc_b2b_company'][] = $companyId;
        $db->insert('mc_b2b_company_member', [
            'company_id' => $companyId, 'customer_id' => $buyer['id'], 'role' => 'admin', 'status' => 'active',
            'spending_limit_minor' => null, 'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($created),
        ]);
        $db->insert('mc_b2b_price_list', [
            'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'name' => CanonicalUiText::get('demo.commerce.b2b.price_list'),
            'currency' => $ctx['currency'], 'priority' => 50, 'status' => 'active', 'starts_at' => null, 'ends_at' => null,
            'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($created),
        ]);
        $listId = (int) $db->lastInsertId();
        $loose['mc_b2b_price_list'][] = $listId;
        $db->insert('mc_b2b_price_list_company', ['price_list_id' => $listId, 'company_id' => $companyId]);
        foreach ([6, 8, 9] as $ix) {
            $p = $products[$ix % count($products)];
            $db->insert('mc_b2b_price_tier', [
                'price_list_id' => $listId, 'variant_id' => $p['variant_id'], 'min_quantity' => '3.000000', 'max_quantity' => null,
                'amount_minor' => intdiv($p['price'] * 90, 100), 'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($created),
            ]);
        }

        return ['company_id' => $companyId, 'customer_id' => $buyer['id'], 'name' => $name, 'tax_id' => $taxId, 'terms' => 14];
    }

    /**
     * @param list<array{id:int,name:string,email:string,phone:string,created:DateTimeImmutable}> $customers
     * @param list<array{product_id:int,variant_id:int,sku:string,name:string,price:int,path:string}> $products
     * @param array<string,array<string,mixed>> $promotions
     * @param array<int,array{id:int,initial:int,balance:int,last4:string,currency:string}> $giftCards
     * @param array<string,mixed>|null $b2b
     * @param array<string,int> $counts
     * @return list<array<string,mixed>>
     */
    private function seedOrders(Connection $db, array $ctx, DateTimeImmutable $now, array $customers, array $products, array $promotions, array &$giftCards, ?array $b2b, array &$counts): array
    {
        $country = (string) ($db->fetchOne('SELECT default_country FROM mc_store WHERE id=?', [$ctx['store_id']]) ?: 'UA');
        $currency = (string) $ctx['currency'];
        $loyaltyCfg = $db->fetchAssociative('SELECT enabled,earn_points_per_major,redeem_minor_per_point FROM mc_loyalty_config WHERE store_id=?', [$ctx['store_id']]);
        $earnPerMajor = is_array($loyaltyCfg) ? (int) $loyaltyCfg['earn_points_per_major'] : 1;
        $redeemMinor = is_array($loyaltyCfg) ? max(1, (int) $loyaltyCfg['redeem_minor_per_point']) : 1;
        $loyaltyEnabled = !is_array($loyaltyCfg) || (int) $loyaltyCfg['enabled'] === 1;
        $accounts = [];
        $redemptions = [];
        $out = [];
        $rng = 20260930;
        $n = count($products);

        foreach (self::ORDERS as $i => [$days, $hours, $buyerIx, $profile, $method, $carrier, $items, $promoCode, $sourceKey, $extra]) {
            $number = self::ORDER_PREFIX . sprintf('%06d', 100241 + $i * 13);
            [$orderStatus, $paymentStatus, $fulfilStatus, $paymentRowStatus, $shipmentStatus] = self::PROFILES[$profile];
            if ($method === 'cash_on_delivery' && in_array($profile, ['shipped', 'exception'], true)) {
                // Cash on delivery is collected by the courier: still unpaid while the parcel is in transit.
                $paymentStatus = 'pending';
                $paymentRowStatus = 'pending';
            }
            $isB2b = ($extra['b2b'] ?? false) === true && $b2b !== null;
            $customer = $buyerIx >= 0 ? ($customers[$buyerIx] ?? null) : null;
            $guest = $buyerIx < 0 ? self::GUESTS[-$buyerIx - 1] : null;
            $name = $customer['name'] ?? (string) $guest[0];
            $email = $customer['email'] ?? ($guest[1] . '@' . self::EMAIL_DOMAIN);
            $phone = $customer['phone'] ?? sprintf('+38067%07d', 3400000 + $i * 20411);
            $created = $now->modify('-' . $days . ' days')->modify('-' . $hours . ' hours')->modify('-' . (($i * 17) % 60) . ' minutes');
            if ($customer !== null && $created < $customer['created']) {
                $created = $customer['created']->modify('+2 hours');
            }
            $at = fn (int $minutes): DateTimeImmutable => $this->clamp($created->modify('+' . $minutes . ' minutes'), $now);

            $lines = [];
            $subtotal = 0;
            $lineTax = 0;
            foreach ($items as [$pix, $qty]) {
                $p = $products[$pix % $n];
                $unit = $isB2b && $qty >= 3 ? intdiv($p['price'] * 90, 100) : $p['price'];
                $line = $unit * $qty;
                $tax = intdiv($line * self::VAT_BPS, 10000 + self::VAT_BPS);
                $lines[] = ['p' => $p, 'qty' => $qty, 'unit' => $unit, 'line' => $line, 'tax' => $tax];
                $subtotal += $line;
                $lineTax += $tax;
            }

            $promo = null;
            $discount = 0;
            if ($promoCode !== null && isset($promotions[$promoCode]) && $subtotal >= $promotions[$promoCode]['min']) {
                $pd = $promotions[$promoCode];
                $discount = $pd['type'] === 'percent' ? intdiv($subtotal * min(10000, $pd['value']), 10000) : min($subtotal, $pd['value']);
                if ($pd['max'] !== null) {
                    $discount = min($discount, $pd['max']);
                }
                $promo = $discount > 0 ? $pd + ['code' => $promoCode] : null;
                $discount = $promo === null ? 0 : $discount;
            }

            $gift = 0;
            $giftIx = null;
            if (isset($extra['gift']) && isset($giftCards[$extra['gift'][0]])) {
                $giftIx = (int) $extra['gift'][0];
                $gift = min((int) $extra['gift'][1], $giftCards[$giftIx]['balance'], $subtotal - $discount);
            }
            $points = 0;
            if (isset($extra['points']) && $customer !== null && $loyaltyEnabled && ($accounts[$customer['id']]['bal'] ?? 0) >= (int) $extra['points']) {
                $points = (int) $extra['points'];
            }
            $loyaltyMinor = $points * $redeemMinor;
            $shipping = ($subtotal - $discount) >= 200000 ? 0 : self::CARRIERS[$carrier][2];
            $total = max(0, $subtotal - $discount - $gift - $loyaltyMinor + $shipping);
            $tax = max(0, $lineTax - intdiv(($discount + $gift + $loyaltyMinor) * self::VAT_BPS, 10000 + self::VAT_BPS)) + intdiv($shipping * self::VAT_BPS, 10000 + self::VAT_BPS);

            $comment = isset($extra['comment']) ? CanonicalUiText::get('demo.commerce.order.comment.' . $extra['comment']) : null;
            $cityRow = self::CITIES[($i * 3 + 1) % count(self::CITIES)];
            $lastEvent = $created;

            $db->insert('mc_sales_order', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'customer_id' => $customer['id'] ?? null,
                'b2b_company_id' => $isB2b ? $b2b['company_id'] : null, 'b2b_approval_status' => $isB2b ? 'approved' : null,
                'purchase_order_number' => $isB2b ? 'PO-2026-0142' : null, 'payment_terms_days' => $isB2b ? $b2b['terms'] : null,
                'due_at' => $isB2b ? $this->fmt($created->modify('+' . $b2b['terms'] . ' days')) : null,
                'order_number' => $number, 'checkout_idempotency_key' => 'demo-checkout-' . ($i + 1),
                'status' => $orderStatus, 'payment_status' => $paymentStatus, 'fulfillment_status' => $fulfilStatus,
                'currency' => $currency, 'prices_include_tax' => 1, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount,
                'gift_card_minor' => $gift, 'gift_card_last4' => $giftIx !== null ? $giftCards[$giftIx]['last4'] : null,
                'loyalty_minor' => $loyaltyMinor, 'loyalty_points_spent' => $points, 'shipping_minor' => $shipping, 'tax_minor' => $tax,
                'tax_country_code' => $country, 'tax_calculation_mode' => 'included', 'total_minor' => $total,
                'customer_email' => $email, 'customer_email_normalized' => $email, 'customer_phone' => $phone, 'customer_name' => $name,
                'customer_comment' => $comment, 'company_name' => $isB2b ? $b2b['name'] : null, 'company_tax_id' => $isB2b ? $b2b['tax_id'] : null,
                'locale' => $ctx['locale'], 'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($created),
            ]);
            $orderId = (int) $db->lastInsertId();

            $itemIds = [];
            foreach ($lines as $l) {
                $p = $l['p'];
                $db->insert('mc_sales_order_item', [
                    'order_id' => $orderId, 'product_id' => $p['product_id'], 'variant_id' => $p['variant_id'], 'sku' => $p['sku'], 'name' => $p['name'],
                    'quantity' => number_format((float) $l['qty'], 6, '.', ''), 'unit_code' => 'item', 'unit_price_minor' => $l['unit'],
                    'unit_price_net_minor' => $l['unit'] - intdiv($l['unit'] * self::VAT_BPS, 10000 + self::VAT_BPS), 'unit_price_gross_minor' => $l['unit'],
                    'line_total_minor' => $l['line'], 'tax_minor' => $l['tax'], 'tax_rate_bps' => self::VAT_BPS, 'tax_class_code' => null,
                    'snapshot' => json_encode(['sku' => $p['sku'], 'name' => $p['name'], 'unit_code' => 'item', 'purchase_mode' => 'auto'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                $itemIds[] = (int) $db->lastInsertId();
            }

            // Fulfilment (every order is physical).
            $destination = [
                'country' => $country, 'region' => $cityRow[1], 'provider' => $carrier, 'city_id' => 'demo-city-' . (($i * 3 + 1) % count(self::CITIES)),
                'city' => $cityRow[0], 'point_id' => 'demo-point-' . ($i + 1), 'point' => $cityRow[2], 'manual' => '',
            ];
            $tracking = $shipmentStatus !== null ? $this->trackingNumber($carrier, $i, $rng) : null;
            $fulfillmentUpdated = $created;
            if (in_array($fulfilStatus, ['preparing', 'shipped', 'delivered'], true)) {
                $fulfillmentUpdated = $at($fulfilStatus === 'preparing' ? 180 : ($fulfilStatus === 'shipped' ? 1500 : 4300));
            } elseif ($fulfilStatus === 'cancelled') {
                $fulfillmentUpdated = $at(400);
            }
            $db->insert('mc_fulfillment', [
                'public_id' => $this->publicIds->binary(), 'order_id' => $orderId, 'provider_code' => $carrier, 'service_type' => self::CARRIERS[$carrier][1],
                'status' => $fulfilStatus === 'unfulfilled' ? 'pending' : $fulfilStatus,
                'tracking_number' => $tracking, 'destination_snapshot' => json_encode($destination, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'provider_snapshot' => null, 'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($fulfillmentUpdated),
            ]);
            $fulfillmentId = (int) $db->lastInsertId();

            // Payment.
            $paidAt = null;
            if (in_array($paymentRowStatus, ['paid', 'refunded', 'partially_refunded'], true)) {
                $paidAt = $method === 'cash_on_delivery' ? $at(4300) : ($method === 'bank_transfer' || $method === 'b2b_invoice' ? $at(2000) : $at(6));
            }
            $online = in_array($method, ['liqpay', 'monobank', 'wayforpay'], true);
            $refundMinor = 0;
            if ($profile === 'refunded') {
                $refundMinor = $total;
            } elseif ($profile === 'partial_refund') {
                $refundMinor = min($total, $lines[1]['line'] ?? intdiv($total, 3));
            }
            $db->insert('mc_payment', [
                'public_id' => $this->publicIds->binary(), 'order_id' => $orderId, 'provider_code' => $method,
                'provider_reference' => $paidAt !== null && $online ? strtoupper('DEMO-' . substr(sha1($number), 0, 12)) : null,
                'provider_modified_at' => $paidAt !== null ? $this->fmt($paidAt) : null, 'paid_at' => $paidAt !== null ? $this->fmt($paidAt) : null,
                'failed_at' => null, 'cancelled_at' => $paymentRowStatus === 'expired' ? $this->fmt($at(400)) : null,
                'status' => $paymentRowStatus, 'failure_reason' => null, 'amount_minor' => $total, 'refunded_minor' => $refundMinor, 'currency' => $currency,
                'idempotency_key' => 'demo:payment:' . $number, 'metadata' => json_encode(['online' => $online, 'demo' => true], JSON_THROW_ON_ERROR),
                'provider_payload' => null, 'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($paidAt ?? $created),
            ]);
            $paymentId = (int) $db->lastInsertId();
            $refundAt = null;
            if ($refundMinor > 0) {
                $refundAt = $at(4300 + 5 * 1440);
                $db->insert('mc_payment_refund', [
                    'public_id' => $this->publicIds->binary(), 'payment_id' => $paymentId, 'provider_reference' => strtoupper('DEMO-RF-' . substr(sha1($number), 0, 10)),
                    'idempotency_key' => 'demo:refund:' . $number, 'amount_minor' => $refundMinor, 'status' => 'succeeded', 'provider_payload' => null,
                    'created_at' => $this->fmt($refundAt), 'updated_at' => $this->fmt($refundAt),
                ]);
            }

            // Shipment.
            $shipmentId = null;
            if ($shipmentStatus !== null) {
                $shippedAt = $at(1500);
                $deliveredAt = $shipmentStatus === 'delivered' ? $at(4300) : null;
                $shipmentId = $this->insertShipment($db, $ctx['store_id'], $orderId, $fulfillmentId, null, 'outbound', $carrier, $tracking ?? '', $shipmentStatus, $shippedAt, $deliveredAt, [
                    'order_number' => $number, 'customer_name' => $name, 'customer_phone' => $phone, 'customer_email' => $email, 'currency' => $currency, 'destination' => $destination,
                ]);
                $counts['shipments']++;
            }

            // Order timeline.
            $events = [['order.placed', $created, 'customer', $email, ['payment_method' => $method, 'carrier' => $carrier]]];
            if ($method === 'cash_on_delivery' && $profile !== 'cancelled') {
                $events[] = ['order.confirmed_cod', $at(60), 'system', 'system:payments', ['provider' => $method]];
            }
            if ($paidAt !== null) {
                $events[] = ['payment.paid', $paidAt, 'system', 'system:payments', ['provider' => $method, 'amount_minor' => $total]];
            }
            if (isset($extra['note'])) {
                $events[] = ['admin.note', $at(90), 'admin', 'demo:seed', ['note' => CanonicalUiText::get('demo.commerce.order.note.' . $extra['note'])]];
            }
            if (in_array($fulfilStatus, ['preparing', 'shipped', 'delivered'], true)) {
                $events[] = ['fulfillment.status_changed', $at(180), 'admin', 'demo:seed', ['from' => 'pending', 'to' => 'preparing', 'tracking_number' => null]];
            }
            if (in_array($fulfilStatus, ['shipped', 'delivered'], true)) {
                $events[] = ['fulfillment.status_changed', $at(1500), 'admin', 'demo:seed', ['from' => 'preparing', 'to' => 'shipped', 'tracking_number' => $tracking]];
            }
            if ($fulfilStatus === 'delivered') {
                $events[] = ['fulfillment.status_changed', $at(4300), 'system', 'system:carrier', ['from' => 'shipped', 'to' => 'delivered', 'tracking_number' => $tracking]];
                if ($orderStatus === 'completed') {
                    $events[] = ['order.completed', $at(4400), 'system', 'system:orders', []];
                }
            }
            if ($profile === 'refunded' && $refundAt !== null) {
                $events[] = ['payment.refunded', $refundAt, 'admin', 'demo:seed', ['amount_minor' => $refundMinor]];
            }
            if ($profile === 'partial_refund' && $refundAt !== null) {
                $events[] = ['payment.partially_refunded', $refundAt, 'admin', 'demo:seed', ['amount_minor' => $refundMinor]];
            }
            if ($profile === 'cancelled') {
                $events[] = ['order.cancelled', $at(400), 'admin', 'demo:seed', ['reason' => 'demo']];
            }
            usort($events, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
            foreach ($events as $seq => [$type, $when, $actorType, $actor, $payload]) {
                $db->insert('mc_order_event', [
                    'order_id' => $orderId, 'sequence_no' => $seq + 1, 'event_type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'actor_type' => $actorType, 'actor_subject' => $actor, 'created_at' => $this->fmt($when),
                ]);
                $lastEvent = $when > $lastEvent ? $when : $lastEvent;
            }
            $db->update('mc_sales_order', ['updated_at' => $this->fmt($lastEvent)], ['id' => $orderId]);

            // Attribution.
            [$srcSource, $srcMedium, $srcCampaign] = self::SOURCES[$sourceKey];
            [$firstSource, $firstMedium, $firstCampaign] = $i % 3 === 0 ? self::SOURCES['organic'] : self::SOURCES[$sourceKey];
            $db->insert('mc_order_attribution', [
                'order_id' => $orderId, 'store_id' => $ctx['store_id'], 'first_source' => $firstSource, 'first_medium' => $firstMedium, 'first_campaign' => $firstCampaign !== '' ? $firstCampaign : null,
                'last_source' => $srcSource, 'last_medium' => $srcMedium, 'last_campaign' => $srcCampaign !== '' ? $srcCampaign : null, 'last_content' => null, 'last_term' => null,
                'landing_url' => '/', 'referrer_url' => $srcSource === 'direct' ? null : 'https://' . ($srcSource === 'google' ? 'www.google.com' : $srcSource . '.com') . '/',
                'captured_at' => $this->fmt($created),
            ]);

            // Promotion usage.
            if ($promo !== null) {
                $db->insert('mc_order_promotion', [
                    'order_id' => $orderId, 'promotion_id' => $promo['id'], 'name' => $promo['name'], 'coupon_code' => $promo['code'], 'discount_minor' => $discount,
                    'snapshot_json' => json_encode(['type' => $promo['type'], 'value' => $promo['value']], JSON_THROW_ON_ERROR),
                ]);
                if ($orderStatus !== 'cancelled') {
                    $db->insert('mc_promotion_redemption', [
                        'promotion_id' => $promo['id'], 'order_id' => $orderId, 'customer_id' => $customer['id'] ?? null, 'email_normalized' => $email,
                        'coupon_code' => $promo['code'], 'discount_minor' => $discount, 'created_at' => $this->fmt($created),
                    ]);
                    $redemptions[$promo['id']] = ($redemptions[$promo['id']] ?? 0) + 1;
                }
            }

            // Gift card redemption.
            if ($giftIx !== null && $gift > 0) {
                $giftCards[$giftIx]['balance'] -= $gift;
                $db->insert('mc_gift_card_transaction', [
                    'gift_card_id' => $giftCards[$giftIx]['id'], 'order_id' => $orderId, 'tx_type' => 'redeem', 'amount_minor' => -$gift,
                    'balance_after_minor' => $giftCards[$giftIx]['balance'], 'idempotency_key' => 'demo:gift:redeem:' . $number, 'created_at' => $this->fmt($created),
                ]);
                $db->update('mc_gift_card', [
                    'balance_minor' => $giftCards[$giftIx]['balance'], 'status' => $giftCards[$giftIx]['balance'] === 0 ? 'exhausted' : 'active', 'updated_at' => $this->fmt($created),
                ], ['id' => $giftCards[$giftIx]['id']]);
            }

            // Loyalty ledger (registered customers only).
            if ($customer !== null && $loyaltyEnabled) {
                $cid = $customer['id'];
                $accounts[$cid] ??= ['bal' => 0, 'earned' => 0, 'spent' => 0];
                if ($points > 0) {
                    $accounts[$cid]['bal'] -= $points;
                    $accounts[$cid]['spent'] += $points;
                    $this->loyaltyTx($db, $ctx['store_id'], $cid, $orderId, 'redeem', -$points, $accounts[$cid]['bal'], 'demo:loyalty:redeem:' . $number, $created);
                    $counts['loyalty_transactions']++;
                }
                if ($paidAt !== null) {
                    $earn = intdiv(max(0, $total), 100) * $earnPerMajor;
                    if ($earn > 0) {
                        $accounts[$cid]['bal'] += $earn;
                        $accounts[$cid]['earned'] += $earn;
                        $this->loyaltyTx($db, $ctx['store_id'], $cid, $orderId, 'earn', $earn, $accounts[$cid]['bal'], 'demo:loyalty:earn:' . $number, $paidAt);
                        $counts['loyalty_transactions']++;
                    }
                    if ($profile === 'refunded' && $earn > 0 && $refundAt !== null) {
                        $reverse = min($earn, $accounts[$cid]['bal']);
                        $accounts[$cid]['bal'] -= $reverse;
                        $this->loyaltyTx($db, $ctx['store_id'], $cid, $orderId, 'earn_reverse', -$reverse, $accounts[$cid]['bal'], 'demo:loyalty:reverse:' . $number, $refundAt);
                        $counts['loyalty_transactions']++;
                    }
                }
            }

            $out[] = [
                'id' => $orderId, 'number' => $number, 'index' => $i, 'profile' => $profile, 'created' => $created, 'customer' => $customer,
                'email' => $email, 'name' => $name, 'phone' => $phone, 'item_ids' => $itemIds, 'lines' => $lines, 'total' => $total,
                'source' => $sourceKey, 'extra' => $extra, 'carrier' => $carrier, 'shipment_id' => $shipmentId, 'fulfillment_id' => $fulfillmentId,
                'delivered_at' => $fulfilStatus === 'delivered' ? $at(4300) : null, 'destination' => $destination, 'currency' => $currency,
                'shipment_status' => $shipmentStatus,
            ];
            $counts['orders']++;
        }

        foreach ($accounts as $cid => $a) {
            $db->insert('mc_loyalty_account', [
                'store_id' => $ctx['store_id'], 'customer_id' => $cid, 'points_balance' => max(0, $a['bal']), 'lifetime_earned' => $a['earned'],
                'lifetime_spent' => $a['spent'], 'updated_at' => $this->fmt($now),
            ]);
        }
        foreach ($redemptions as $promoId => $used) {
            $db->executeStatement('UPDATE mc_promotion SET usage_count=usage_count+? WHERE id=?', [$used, $promoId]);
        }

        return $out;
    }

    /** @param array<string,mixed> $snapshot */
    private function insertShipment(Connection $db, int $storeId, int $orderId, ?int $fulfillmentId, ?int $returnId, string $direction, string $carrier, string $tracking, string $status, DateTimeImmutable $createdAt, ?DateTimeImmutable $deliveredAt, array $snapshot): int
    {
        $db->insert('mc_shipment', [
            'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'order_id' => $orderId, 'fulfillment_id' => $fulfillmentId, 'return_request_id' => $returnId,
            'direction' => $direction, 'provider_code' => $carrier, 'service_type' => self::CARRIERS[$carrier][1], 'status' => $status, 'external_id' => null,
            'tracking_number' => $tracking, 'carrier_label_url' => null, 'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'note' => null, 'created_by' => 'demo:seed', 'created_at' => $this->fmt($createdAt),
            'updated_at' => $this->fmt($deliveredAt ?? $createdAt), 'cancelled_at' => null, 'delivered_at' => $deliveredAt !== null ? $this->fmt($deliveredAt) : null,
        ]);
        $id = (int) $db->lastInsertId();
        $events = [['shipment.registered', $createdAt, []]];
        if (in_array($status, ['in_transit', 'delivered', 'exception'], true)) {
            $events[] = ['shipment.status_changed', $createdAt->modify('+2 hours'), ['from' => 'registered', 'to' => 'in_transit']];
        }
        if ($status === 'delivered' && $deliveredAt !== null) {
            $events[] = ['shipment.status_changed', $deliveredAt, ['from' => 'in_transit', 'to' => 'delivered']];
        }
        if ($status === 'exception') {
            $events[] = ['shipment.status_changed', $createdAt->modify('+30 hours'), ['from' => 'in_transit', 'to' => 'exception']];
        }
        foreach ($events as [$type, $when, $payload]) {
            $db->insert('mc_shipment_event', [
                'shipment_id' => $id, 'event_type' => $type, 'actor' => 'demo:seed',
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => $this->fmt($when),
            ]);
        }

        return $id;
    }

    private function loyaltyTx(Connection $db, int $storeId, int $customerId, int $orderId, string $type, int $points, int $balanceAfter, string $key, DateTimeImmutable $when): void
    {
        $db->insert('mc_loyalty_transaction', [
            'store_id' => $storeId, 'customer_id' => $customerId, 'order_id' => $orderId, 'tx_type' => $type, 'points' => $points,
            'balance_after' => max(0, $balanceAfter), 'idempotency_key' => $key, 'created_at' => $this->fmt($when),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $orders
     */
    private function seedReturns(Connection $db, array $ctx, DateTimeImmutable $now, array $orders): int
    {
        $seed = 5150;
        // return number => [status, reason, resolution, days after delivery, admin note key, tracking?]
        $plan = [
            1 => ['resolved', 'damaged', 'refund', 3, 2, false],
            2 => ['resolved', 'not_as_described', 'refund', 4, 2, false],
            3 => ['in_transit', 'wrong_item', 'exchange', 2, 1, true],
            4 => ['requested', 'changed_mind', null, 2, null, false],
        ];
        $count = 0;
        foreach ($orders as $o) {
            $rn = (int) ($o['extra']['return'] ?? 0);
            if ($rn === 0 || !isset($plan[$rn]) || $o['delivered_at'] === null) {
                continue;
            }
            [$status, $reason, $resolution, $after, $adminNote, $withTracking] = $plan[$rn];
            $requested = $this->clamp($o['delivered_at']->modify('+' . $after . ' days'), $now);
            $approved = in_array($status, ['requested'], true) ? null : $this->clamp($requested->modify('+5 hours'), $now);
            $received = in_array($status, ['resolved'], true) ? $this->clamp($requested->modify('+4 days'), $now) : null;
            $resolved = $status === 'resolved' && $received !== null ? $this->clamp($received->modify('+1 day'), $now) : null;
            $tracking = $withTracking ? $this->trackingNumber('nova_post', 900 + $rn, $seed) : null;
            $db->insert('mc_return_request', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'order_id' => $o['id'], 'customer_id' => $o['customer']['id'] ?? null,
                'status' => $status, 'reason_code' => $reason, 'customer_note' => CanonicalUiText::get('demo.commerce.return.note.' . $rn),
                'admin_note' => $adminNote !== null ? CanonicalUiText::get('demo.commerce.return.admin.' . $adminNote) : null,
                'resolution' => $resolution, 'return_tracking_number' => $tracking, 'created_at' => $this->fmt($requested),
                'updated_at' => $this->fmt($resolved ?? $received ?? $approved ?? $requested), 'approved_at' => $approved !== null ? $this->fmt($approved) : null,
                'received_at' => $received !== null ? $this->fmt($received) : null, 'resolved_at' => $resolved !== null ? $this->fmt($resolved) : null,
            ]);
            $returnId = (int) $db->lastInsertId();
            // Returned lines: everything for full returns, the second line (or the only line) for partial ones.
            $lineIx = $o['profile'] === 'partial_refund' ? min(1, count($o['lines']) - 1) : null;
            foreach ($o['lines'] as $ix => $line) {
                if ($lineIx !== null && $ix !== $lineIx) {
                    continue;
                }
                $db->insert('mc_return_item', [
                    'return_id' => $returnId, 'order_item_id' => $o['item_ids'][$ix], 'quantity' => number_format((float) $line['qty'], 6, '.', ''),
                    'reason_code' => $reason, 'condition_code' => $status === 'resolved' ? 'opened' : null, 'resolution' => $resolution,
                    'refund_amount_minor' => $status === 'resolved' ? $line['line'] : 0, 'restock' => $status === 'resolved' ? 1 : 0,
                ]);
            }
            $events = [['requested', 'customer', $o['customer']['id'] ?? null, $requested, ['reason' => $reason]]];
            if ($approved !== null) {
                $events[] = ['approved', 'admin', null, $approved, []];
            }
            if ($status === 'in_transit') {
                $events[] = ['in_transit', 'customer', $o['customer']['id'] ?? null, $this->clamp($requested->modify('+1 day'), $now), ['tracking_number' => $tracking]];
            }
            if ($received !== null) {
                $events[] = ['received', 'admin', null, $received, []];
            }
            if ($resolved !== null) {
                $events[] = ['resolved', 'admin', null, $resolved, ['resolution' => $resolution]];
            }
            foreach ($events as [$type, $actorType, $actorId, $when, $payload]) {
                $db->insert('mc_return_event', [
                    'return_id' => $returnId, 'event_type' => $type, 'actor_type' => $actorType, 'actor_id' => $actorId,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => $this->fmt($when),
                ]);
            }
            if ($withTracking && $tracking !== null) {
                $this->insertShipment($db, $ctx['store_id'], $o['id'], null, $returnId, 'return', 'nova_post', $tracking, 'in_transit', $this->clamp($requested->modify('+1 day'), $now), null, [
                    'order_number' => $o['number'], 'customer_name' => $o['name'], 'customer_phone' => $o['phone'], 'customer_email' => $o['email'], 'currency' => $o['currency'], 'destination' => $o['destination'],
                ]);
            }
            $count++;
        }

        return $count;
    }

    /** @param list<array<string,mixed>> $orders */
    private function seedWithdrawals(Connection $db, array $ctx, DateTimeImmutable $now, array $orders): int
    {
        $count = 0;
        foreach ($orders as $o) {
            $w = (int) ($o['extra']['withdrawal'] ?? 0);
            if ($w === 0) {
                continue;
            }
            $received = $this->clamp($o['created']->modify('+' . (2 + $w) . ' days'), $now);
            $processed = $w === 2;
            $db->insert('mc_withdrawal_notice', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'order_id' => $o['id'], 'order_reference' => $o['number'],
                'customer_name' => $o['name'], 'email' => $o['email'], 'email_normalized' => $o['email'],
                'scope_note' => CanonicalUiText::get('demo.commerce.withdrawal.' . $w), 'locale' => $ctx['locale'],
                'status' => $processed ? 'processed' : 'received', 'ip_hash' => hash('sha256', 'demo-withdrawal-' . $w),
                'received_at' => $this->fmt($received), 'acknowledged_at' => $processed ? $this->fmt($this->clamp($received->modify('+3 hours'), $now)) : null,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param list<array{id:int,name:string,email:string,phone:string,created:DateTimeImmutable}> $customers
     * @param list<array{product_id:int,variant_id:int,sku:string,name:string,price:int,path:string}> $products
     */
    private function seedReviews(Connection $db, array $ctx, DateTimeImmutable $now, array $customers, array $products): int
    {
        // rating, status, days ago, helpful, reply key
        $plan = [
            [5, 'published', 55, 6, 1], [5, 'published', 48, 3, null], [4, 'published', 44, 2, null], [4, 'published', 39, 1, null],
            [3, 'published', 35, 4, null], [2, 'published', 31, 5, 2], [5, 'published', 27, 2, null], [5, 'published', 22, 0, null],
            [4, 'published', 18, 1, null], [1, 'published', 15, 7, null], [5, 'pending', 8, 0, null], [4, 'pending', 5, 0, null],
            [3, 'pending', 2, 0, null], [5, 'published', 12, 1, null], [4, 'rejected', 10, 0, null], [2, 'published', 6, 2, null],
        ];
        $count = 0;
        $n = count($products);
        foreach ($plan as $i => [$rating, $status, $days, $helpful, $replyKey]) {
            $customer = $customers[$i % count($customers)];
            $created = $now->modify('-' . $days . ' days')->modify('-' . (($i * 53) % 700) . ' minutes');
            if ($created < $customer['created']) {
                $created = $this->clamp($customer['created']->modify('+1 day'), $now);
            }
            $published = $status === 'published' ? $this->clamp($created->modify('+1 day'), $now) : null;
            $db->insert('mc_product_review', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'product_id' => $products[($i * 3 + 1) % $n]['product_id'],
                'customer_id' => $customer['id'], 'locale' => $ctx['locale'], 'author_name' => $customer['name'], 'rating' => $rating,
                'title' => CanonicalUiText::get('demo.commerce.review.' . ($i + 1) . '.title'), 'body' => CanonicalUiText::get('demo.commerce.review.' . ($i + 1) . '.body'),
                'merchant_reply' => $replyKey !== null ? CanonicalUiText::get('demo.commerce.review.reply.' . $replyKey) : null,
                'merchant_replied_at' => $replyKey !== null && $published !== null ? $this->fmt($this->clamp($published->modify('+3 hours'), $now)) : null,
                'verified_purchase' => $i % 4 === 3 ? 0 : 1, 'status' => $status, 'helpful_count' => $helpful,
                'created_at' => $this->fmt($created), 'published_at' => $published !== null ? $this->fmt($published) : null,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param list<array{id:int,name:string,email:string,phone:string,created:DateTimeImmutable}> $customers
     * @param list<array{product_id:int,variant_id:int,sku:string,name:string,price:int,path:string}> $products
     */
    private function seedQuestions(Connection $db, array $ctx, DateTimeImmutable $now, array $customers, array $products): int
    {
        // status, days ago, has answer, answered (draft answers stay pending)
        $plan = [
            ['published', 50, true], ['published', 42, true], ['published', 36, true], ['published', 29, true], ['published', 21, true], ['published', 16, true],
            ['pending', 3, false], ['pending', 1, false], ['pending', 6, true], ['published', 11, true], ['rejected', 9, false],
        ];
        $count = 0;
        $n = count($products);
        foreach ($plan as $i => [$status, $days, $answered]) {
            $customer = $i % 3 === 0 ? null : $customers[($i * 2) % count($customers)];
            $created = $now->modify('-' . $days . ' days')->modify('-' . (($i * 71) % 800) . ' minutes');
            if ($customer !== null && $created < $customer['created']) {
                $created = $this->clamp($customer['created']->modify('+1 day'), $now);
            }
            $answeredAt = $answered ? $this->clamp($created->modify('+6 hours'), $now) : null;
            $key = $i + 1;
            $db->insert('mc_product_question', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'product_id' => $products[($i * 5 + 2) % $n]['product_id'],
                'customer_id' => $customer['id'] ?? null, 'locale' => $ctx['locale'],
                'author_name' => $customer['name'] ?? self::GUESTS[$i % count(self::GUESTS)][0],
                'question' => CanonicalUiText::get('demo.commerce.question.' . $key),
                'answer' => $answered ? CanonicalUiText::get('demo.commerce.answer.' . $key) : null,
                'status' => $status, 'created_at' => $this->fmt($created),
                'answered_at' => $answeredAt !== null ? $this->fmt($answeredAt) : null,
                'published_at' => $status === 'published' && $answeredAt !== null ? $this->fmt($answeredAt) : null,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param list<array{id:int,name:string,email:string,phone:string,created:DateTimeImmutable}> $customers
     * @param list<array{product_id:int,variant_id:int,sku:string,name:string,price:int,path:string}> $products
     */
    private function seedInquiries(Connection $db, array $ctx, DateTimeImmutable $now, array $customers, array $products): int
    {
        // type, status, days ago, customer index or guest (-1..-4), has product
        $plan = [
            ['contact', 'new', 0, -1, false], ['callback', 'new', 1, 5, false], ['product_question', 'in_progress', 4, -2, true],
            ['price_request', 'in_progress', 7, 10, true], ['quick_order', 'new', 2, -3, true], ['contact', 'resolved', 14, 3, false],
            ['contact', 'closed', 21, 7, false], ['callback', 'resolved', 18, -4, false],
        ];
        $count = 0;
        $n = count($products);
        foreach ($plan as $i => [$type, $status, $days, $who, $withProduct]) {
            $customer = $who >= 0 ? $customers[$who % count($customers)] : null;
            $name = $customer['name'] ?? self::GUESTS[-$who - 1][0];
            $email = $customer['email'] ?? (self::GUESTS[-$who - 1][1] . '@' . self::EMAIL_DOMAIN);
            $phone = $customer['phone'] ?? sprintf('+38093%07d', 5100000 + $i * 30011);
            $created = $now->modify('-' . $days . ' days')->modify('-' . (($i * 43) % 500) . ' minutes');
            $product = $withProduct ? $products[($i * 4 + 3) % $n] : null;
            $db->insert('mc_customer_inquiry', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'product_id' => $product['product_id'] ?? null,
                'inquiry_type' => $type, 'status' => $status, 'customer_name' => $name, 'email' => $email, 'phone' => $phone,
                'message' => CanonicalUiText::get('demo.commerce.inquiry.' . ($i + 1)), 'source_url' => $product['path'] ?? '/contacts',
                'admin_note' => null, 'created_at' => $this->fmt($created),
                'updated_at' => $this->fmt($status === 'new' ? $created : $this->clamp($created->modify('+5 hours'), $now)),
            ]);
            $count++;
        }

        return $count;
    }

    /** @param list<array{id:int,name:string,email:string,phone:string,created:DateTimeImmutable}> $customers */
    private function seedSubscribers(Connection $db, array $ctx, DateTimeImmutable $now, array $customers): int
    {
        $rows = [];
        foreach ([0, 1, 2, 3, 5, 7, 9, 12] as $ix) {
            $rows[] = [$customers[$ix]['email'], 'active', 'account'];
        }
        foreach ([1, 2, 3, 4, 5] as $k) {
            $rows[] = [sprintf('subscriber%02d@%s', $k, self::EMAIL_DOMAIN), $k <= 2 ? 'active' : 'pending', $k % 2 === 0 ? 'footer' : 'checkout'];
        }
        foreach ([6, 7] as $k) {
            $rows[] = [sprintf('subscriber%02d@%s', $k, self::EMAIL_DOMAIN), 'unsubscribed', 'footer'];
        }
        $count = 0;
        foreach ($rows as $i => [$email, $status, $source]) {
            $consent = $now->modify('-' . (85 - $i * 5) . ' days')->modify('-' . (($i * 29) % 400) . ' minutes');
            $db->insert('mc_marketing_subscriber', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'email' => $email, 'email_normalized' => $email, 'locale' => $ctx['locale'],
                'status' => $status, 'confirm_token_hash' => $status === 'pending' ? hash('sha256', 'demo-confirm-' . $i, true) : null,
                'consent_source' => $source, 'consent_at' => $this->fmt($consent),
                'confirmed_at' => $status === 'pending' ? null : $this->fmt($this->clamp($consent->modify('+20 minutes'), $now)),
                'unsubscribed_at' => $status === 'unsubscribed' ? $this->fmt($this->clamp($consent->modify('+30 days'), $now)) : null,
                'created_at' => $this->fmt($consent), 'updated_at' => $this->fmt($consent),
            ]);
            $count++;
        }

        return $count;
    }

    /** @param array<string,list<int>> $loose */
    private function seedCampaigns(Connection $db, array $ctx, DateTimeImmutable $now, array &$loose): int
    {
        $active = (int) $db->fetchOne("SELECT COUNT(*) FROM mc_marketing_subscriber WHERE store_id=? AND status='active'", [$ctx['store_id']]);
        $defs = [
            [1, 'enqueued', $active, $now->modify('-26 days')],
            [2, 'draft', 0, $now->modify('-1 day')],
        ];
        foreach ($defs as [$k, $status, $recipients, $created]) {
            $db->insert('mc_marketing_campaign', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'],
                'subject' => CanonicalUiText::get('demo.commerce.campaign.' . $k . '.subject'), 'body_text' => CanonicalUiText::get('demo.commerce.campaign.' . $k . '.body'),
                'segment_code' => 'all_subscribers', 'status' => $status, 'recipient_count' => $recipients, 'cursor_subscriber_id' => 0,
                'created_at' => $this->fmt($created), 'enqueued_at' => $status === 'enqueued' ? $this->fmt($created->modify('+10 minutes')) : null,
            ]);
            $loose['mc_marketing_campaign'][] = (int) $db->lastInsertId();
        }

        return count($defs);
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @param list<array{product_id:int,variant_id:int,sku:string,name:string,price:int,path:string}> $products
     * @param array<string,list<string>> $pageRows
     */
    private function seedAnalytics(Connection $db, array $ctx, DateTimeImmutable $now, array $orders, array $products, array &$pageRows): int
    {
        $categoryPaths = $db->fetchFirstColumn("SELECT path FROM mc_seo_route WHERE store_id=? AND locale=? AND entity_type='category' ORDER BY id LIMIT 6", [$ctx['store_id'], $ctx['locale']]);
        $paths = ['/'];
        $weights = [24];
        foreach ($categoryPaths as $k => $p) {
            $paths[] = '/' . ltrim((string) $p, '/');
            $weights[] = 9 - min(4, $k);
        }
        foreach (array_slice($products, 0, 8) as $k => $p) {
            $paths[] = $p['path'];
            $weights[] = 8 - min(5, intdiv($k, 2));
        }
        array_push($paths, '/cart', '/checkout');
        array_push($weights, 4, 2);
        $paths = array_values(array_unique($paths));
        $weights = array_slice($weights, 0, count($paths));
        $totalWeight = array_sum($weights);
        $pick = function (int &$state) use ($paths, $weights, $totalWeight): string {
            $r = $this->next($state, 0, $totalWeight - 1);
            foreach ($weights as $ix => $w) {
                if ($r < $w) {
                    return $paths[$ix];
                }
                $r -= $w;
            }

            return $paths[0];
        };
        // source key, weight, referrer host
        $mix = [['organic', 38, 'www.google.com'], ['direct', 24, ''], ['instagram', 10, 'l.instagram.com'], ['cpc', 9, 'www.google.com'], ['facebook', 8, 'l.facebook.com'], ['newsletter', 7, '']];
        $mixTotal = array_sum(array_column($mix, 1));
        $hours = [0, 0, 0, 0, 0, 1, 1, 2, 3, 4, 5, 5, 5, 5, 5, 6, 6, 6, 7, 8, 8, 7, 5, 2];

        $orderDays = [];
        foreach ($orders as $o) {
            $day = $o['created']->format('Y-m-d');
            $orderDays[$day][] = $o;
        }

        $state = 424242;
        $sessions = [];
        $views = [];
        $entrances = [];
        $today = $now->setTime(0, 0);
        for ($d = 29; $d >= 0; $d--) {
            $day = $today->modify('-' . $d . ' days');
            $dow = (int) $day->format('N');
            $base = 34 + (29 - $d) + ($dow >= 6 ? 12 : 0) + $this->next($state, 0, 14);
            $mine = $orderDays[$day->format('Y-m-d')] ?? [];
            $mineIx = 0;
            for ($s = 0; $s < $base; $s++) {
                $r = $this->next($state, 0, $mixTotal - 1);
                $srcKey = 'organic';
                $host = '';
                foreach ($mix as [$key, $w, $refHost]) {
                    if ($r < $w) {
                        $srcKey = $key;
                        $host = $refHost;
                        break;
                    }
                    $r -= $w;
                }
                $ordered = false;
                if ($mineIx < count($mine) && $s % max(1, intdiv($base, count($mine) + 1)) === 0 && $s > 0) {
                    $srcKey = (string) $mine[$mineIx]['source'];
                    $host = (string) (array_column($mix, 2, 0)[$srcKey] ?? '');
                    $ordered = true;
                    $mineIx++;
                }
                $pv = $ordered ? $this->next($state, 5, 11) : $this->pageviews($state);
                $viewed = $ordered || ($pv > 1 && $this->next($state, 0, 99) < 70);
                $cart = $ordered || ($viewed && $this->next($state, 0, 99) < 22);
                $checkout = $ordered || ($cart && $this->next($state, 0, 99) < 45);
                $hour = $hours[$this->next($state, 0, count($hours) - 1)];
                $hour = ($hour + $this->next($state, 6, 18)) % 24;
                $started = $day->setTime($hour, $this->next($state, 0, 59), $this->next($state, 0, 59));
                if ($started > $now) {
                    $started = $now->modify('-' . $this->next($state, 5, 200) . ' minutes');
                }
                [$source, $medium, $campaign] = self::SOURCES[$srcKey];
                $landing = $pick($state);
                $device = $this->next($state, 0, 99) < 58 ? 'mobile' : ($this->next($state, 0, 99) < 88 ? 'desktop' : 'tablet');
                $dayKey = $day->format('Y-m-d');
                $entrances[$dayKey][$landing] = ($entrances[$dayKey][$landing] ?? 0) + 1;
                $views[$dayKey][$landing] = ($views[$dayKey][$landing] ?? 0) + 1;
                for ($v = 1; $v < $pv; $v++) {
                    $page = $pick($state);
                    $views[$dayKey][$page] = ($views[$dayKey][$page] ?? 0) + 1;
                }
                $sessions[] = [
                    $ctx['store_id'], self::VISITOR_MARK . substr(hash('sha256', 'demo-visitor-' . count($sessions), true), 0, 12),
                    $started->format('Y-m-d H:i:s'), $started->modify('+' . ($pv * 70) . ' seconds')->format('Y-m-d H:i:s'), $pv, $landing,
                    $host, $source, $medium, $campaign, $device, $viewed ? 1 : 0, $cart ? 1 : 0, $checkout ? 1 : 0, $ordered ? 1 : 0,
                ];
            }
        }
        $this->bulk($db, 'mc_analytics_session', ['store_id', 'visitor_hash', 'started_at', 'last_seen_at', 'pageviews', 'landing_path', 'referrer_host', 'source', 'medium', 'campaign', 'device', 'viewed_product', 'added_to_cart', 'started_checkout', 'ordered'], $sessions);

        $pageInsert = [];
        foreach ($views as $dayKey => $byPath) {
            foreach ($byPath as $path => $count) {
                if (mb_strlen((string) $path) > 190) {
                    continue;
                }
                $pageInsert[] = [$ctx['store_id'], $dayKey, (string) $path, $count, $entrances[$dayKey][$path] ?? 0];
            }
        }
        foreach ($pageInsert as $row) {
            $inserted = $db->executeStatement('INSERT IGNORE INTO mc_analytics_page_daily (store_id,day,path,views,entrances) VALUES (?,?,?,?,?)', $row);
            if ($inserted > 0) {
                $pageRows[(string) $row[1]][] = (string) $row[2];
            }
        }

        return count($sessions);
    }

    /**
     * One converted cart per demo order plus abandoned carts, so cart conversion and abandonment widgets have data.
     *
     * @param list<array<string,mixed>> $orders
     * @param list<array{id:int,name:string,email:string,phone:string,created:DateTimeImmutable}> $customers
     * @param list<array{product_id:int,variant_id:int,sku:string,name:string,price:int,path:string}> $products
     */
    private function seedCarts(Connection $db, array $ctx, DateTimeImmutable $now, array $orders, array $customers, array $products): int
    {
        $carts = [];
        foreach ($orders as $o) {
            $items = [];
            foreach ($o['lines'] as $l) {
                $items[] = [$l['p']['variant_id'], $l['qty'], $l['unit']];
            }
            $carts[] = ['converted', $o['created']->modify('-25 minutes'), $o['customer']['id'] ?? null, $items];
        }
        $n = count($products);
        for ($k = 0; $k < 16; $k++) {
            $p = $products[($k * 7 + 3) % $n];
            $items = [[$p['variant_id'], 1 + ($k % 3 === 0 ? 1 : 0), $p['price']]];
            if ($k % 4 === 1) {
                $q = $products[($k * 5 + 1) % $n];
                $items[] = [$q['variant_id'], 1, $q['price']];
            }
            $created = $now->modify('-' . (1 + $k * 2) . ' days')->modify('-' . (($k * 97) % 600) . ' minutes');
            $carts[] = ['active', $created, $k % 3 === 0 ? $customers[($k + 4) % count($customers)]['id'] : null, $items];
        }
        $count = 0;
        foreach ($carts as $i => [$status, $created, $customerId, $items]) {
            $updated = $this->clamp($created->modify($status === 'active' ? '+40 minutes' : '+30 minutes'), $now);
            $db->insert('mc_cart', [
                'public_id' => $this->publicIds->binary(), 'store_id' => $ctx['store_id'], 'customer_id' => $customerId,
                'token_hash' => self::VISITOR_MARK . substr(hash('sha256', 'demo-cart-' . $i, true), 0, 28), 'currency' => $ctx['currency'],
                'status' => $status, 'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($updated),
                'expires_at' => $this->fmt($created->modify('+7 days')),
            ]);
            $cartId = (int) $db->lastInsertId();
            foreach ($items as [$variantId, $qty, $unit]) {
                $db->insert('mc_cart_item', [
                    'cart_id' => $cartId, 'variant_id' => $variantId, 'quantity' => number_format((float) $qty, 6, '.', ''), 'unit_code' => 'item',
                    'unit_price_minor' => $unit, 'metadata' => null, 'created_at' => $this->fmt($created), 'updated_at' => $this->fmt($updated),
                ]);
            }
            $count++;
        }

        return $count;
    }

    private function seedSearchLog(Connection $db, array $ctx, DateTimeImmutable $now): int
    {
        // term number => result count (0 = zero-result search)
        $results = [1 => 12, 2 => 5, 3 => 6, 4 => 4, 5 => 3, 6 => 3, 7 => 1, 8 => 2, 9 => 1, 10 => 2, 11 => 0, 12 => 0, 13 => 0, 14 => 7];
        $rows = [];
        $state = 777;
        for ($term = 1; $term <= self::SEARCH_TERMS; $term++) {
            $repeats = $term <= 6 ? 20 - $term : ($results[$term] === 0 ? 9 - ($term - 11) * 2 : 6);
            $text = mb_substr(CanonicalUiText::get('demo.commerce.search.' . $term), 0, 120);
            for ($k = 0; $k < $repeats; $k++) {
                $when = $now->modify('-' . $this->next($state, 0, 29) . ' days')->modify('-' . $this->next($state, 1, 1300) . ' minutes');
                $rows[] = [$ctx['store_id'], $ctx['locale'], $text, $this->searchHash($term), $results[$term], $this->fmt($when)];
            }
        }
        $this->bulk($db, 'mc_search_query_log', ['store_id', 'locale', 'query_text', 'query_hash', 'result_count', 'created_at'], $rows);

        return count($rows);
    }

    private function pageviews(int &$state): int
    {
        $r = $this->next($state, 0, 99);

        return $r < 30 ? 1 : ($r < 65 ? $this->next($state, 2, 3) : ($r < 90 ? $this->next($state, 4, 6) : $this->next($state, 7, 10)));
    }

    private function searchHash(int $term): string
    {
        return hash('sha256', 'demo-search-' . $term, true);
    }

    /** @param list<string> $columns @param list<list<scalar|null>> $rows */
    private function bulk(Connection $db, string $table, array $columns, array $rows, int $chunk = 100): void
    {
        $placeholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, $chunk) as $part) {
            $db->executeStatement(
                'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES ' . implode(',', array_fill(0, count($part), $placeholder)),
                array_merge(...$part),
            );
        }
    }

    /** Deterministic pseudo-random integer in [min,max]; local state so global mt_rand is untouched. */
    private function next(int &$state, int $min, int $max): int
    {
        $state = ($state * 1103515245 + 12345) & 0x7fffffff;

        return $max <= $min ? $min : $min + (($state >> 8) % ($max - $min + 1));
    }

    private function trackingNumber(string $carrier, int $seed, int &$rng): string
    {
        $rng = ($rng * 1103515245 + 12345 + $seed) & 0x7fffffff;
        $digits = sprintf('%09d', ($rng >> 3) % 1000000000);

        return match ($carrier) {
            'nova_post' => '20450' . $digits,
            'ukrposhta' => '0503' . $digits,
            'meest' => 'MST' . $digits . '1',
            default => '77' . $digits,
        };
    }

    private function clamp(DateTimeImmutable $value, DateTimeImmutable $now): DateTimeImmutable
    {
        return $value > $now ? $now->modify('-1 minute') : $value;
    }

    private function namespace(int $storeId): string
    {
        return 'demo-' . $storeId;
    }

    private function fmt(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
