<?php

declare(strict_types=1);

namespace Commerce\Modules\Affiliate\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Affiliate programme: a partner gets a link with ?ref=CODE; whoever follows it carries the code for 30 days, and an order placed in that time
 * earns the partner a percentage of the goods and services (not the delivery). A partner never earns on their own orders.
 */
final class AffiliateService
{
    public const COOKIE = 'mc_ref';
    public const DAYS = 30;

    public function __construct(private readonly Connection $db, private readonly RequestStack $requests)
    {
    }

    public static function validCode(string $code): bool
    {
        return preg_match('/^[a-z0-9]{6,16}$/D', $code) === 1;
    }

    /** @return array{id:int,code:string} */
    public function create(int $storeId, string $name, string $email, string $percent): array
    {
        $name = trim(strip_tags($name));
        $email = mb_strtolower(trim($email));
        $value = (float) str_replace(',', '.', trim($percent));
        if ($name === '' || mb_strlen($name) > 190 || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) || $value <= 0 || $value > 90) {
            throw new \DomainException('affiliate_input');
        }
        for ($try = 0; $try < 5; ++$try) {
            $code = substr(strtolower(bin2hex(random_bytes(6))), 0, 8);
            if ($this->db->fetchOne('SELECT 1 FROM mc_affiliate WHERE code=?', [$code]) === false) {
                $this->db->insert('mc_affiliate', ['store_id' => $storeId, 'name' => $name, 'email' => $email, 'code' => $code, 'commission_percent' => number_format($value, 2, '.', ''), 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s.u')]);

                return ['id' => (int) $this->db->lastInsertId(), 'code' => $code];
            }
        }
        throw new \RuntimeException('affiliate_code');
    }

    /** Remembers a visit with a code (once per visitor and code); returns the code to put in the cookie, or null when it is unknown or paused. */
    public function visit(string $code, bool $alreadyHasIt): ?string
    {
        $code = strtolower(trim($code));
        if (!self::validCode($code)) {
            return null;
        }
        $id = $this->db->fetchOne("SELECT id FROM mc_affiliate WHERE code=? AND status='active'", [$code]);
        if ($id === false) {
            return null;
        }
        if (!$alreadyHasIt) {
            $this->db->insert('mc_affiliate_click', ['affiliate_id' => (int) $id, 'created_at' => gmdate('Y-m-d H:i:s.u')]);
        }

        return $code;
    }

    /** Credits the partner whose code the buyer carries; called while the order is being written. */
    public function attach(int $orderId, int $storeId, int $baseMinor, string $buyerEmail): void
    {
        $request = $this->requests->getMainRequest();
        if ($request === null || str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }
        $code = strtolower((string) $request->cookies->get(self::COOKIE, ''));
        if (!self::validCode($code) || $baseMinor <= 0) {
            return;
        }
        $aff = $this->db->fetchAssociative("SELECT id,email,commission_percent FROM mc_affiliate WHERE code=? AND store_id=? AND status='active'", [$code, $storeId]);
        if (!is_array($aff) || ($aff['email'] !== '' && $buyerEmail !== '' && mb_strtolower($buyerEmail) === (string) $aff['email'])) {
            return;
        }
        $commission = (int) round($baseMinor * (float) $aff['commission_percent'] / 100);
        $this->db->executeStatement(
            'INSERT IGNORE INTO mc_affiliate_order (affiliate_id,order_id,base_minor,commission_minor,payout_status,created_at) VALUES (?,?,?,?,?,?)',
            [(int) $aff['id'], $orderId, $baseMinor, $commission, 'open', gmdate('Y-m-d H:i:s.u')],
        );
    }

    /**
     * @return list<array{id:int,name:string,email:string,code:string,percent:string,status:string,clicks:int,orders:int,sales_minor:int,pending_minor:int,approved_minor:int,paid_minor:int}>
     */
    public function overview(int $storeId): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT a.id,a.name,a.email,a.code,a.commission_percent,a.status,
                    (SELECT COUNT(*) FROM mc_affiliate_click c WHERE c.affiliate_id=a.id) AS clicks,
                    COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','refunded') THEN 1 ELSE 0 END),0) AS orders,
                    COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled','refunded') THEN ao.base_minor ELSE 0 END),0) AS sales,
                    COALESCE(SUM(CASE WHEN ao.payout_status='open' AND o.status NOT IN ('cancelled','refunded','completed') THEN ao.commission_minor ELSE 0 END),0) AS pending,
                    COALESCE(SUM(CASE WHEN ao.payout_status='open' AND o.status='completed' THEN ao.commission_minor ELSE 0 END),0) AS approved,
                    COALESCE(SUM(CASE WHEN ao.payout_status='paid' THEN ao.commission_minor ELSE 0 END),0) AS paid
             FROM mc_affiliate a LEFT JOIN mc_affiliate_order ao ON ao.affiliate_id=a.id LEFT JOIN mc_sales_order o ON o.id=ao.order_id
             WHERE a.store_id=? GROUP BY a.id ORDER BY a.id DESC",
            [$storeId],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'code' => (string) $r['code'], 'percent' => rtrim(rtrim((string) $r['commission_percent'], '0'), '.'),
            'status' => (string) $r['status'], 'clicks' => (int) $r['clicks'], 'orders' => (int) $r['orders'], 'sales_minor' => (int) $r['sales'],
            'pending_minor' => (int) $r['pending'], 'approved_minor' => (int) $r['approved'], 'paid_minor' => (int) $r['paid'],
        ], $rows);
    }

    /** Marks the approved commission (completed orders) of the partner as paid out; returns the amount in minor units. */
    public function payOut(int $storeId, int $affiliateId): int
    {
        return (int) $this->db->transactional(function (Connection $db) use ($storeId, $affiliateId): int {
            $rows = $db->fetchAllAssociative(
                "SELECT ao.id,ao.commission_minor FROM mc_affiliate_order ao JOIN mc_affiliate a ON a.id=ao.affiliate_id JOIN mc_sales_order o ON o.id=ao.order_id
                 WHERE a.id=? AND a.store_id=? AND ao.payout_status='open' AND o.status='completed' FOR UPDATE",
                [$affiliateId, $storeId],
            );
            $sum = 0;
            foreach ($rows as $r) {
                $db->update('mc_affiliate_order', ['payout_status' => 'paid', 'paid_at' => gmdate('Y-m-d H:i:s.u')], ['id' => (int) $r['id']]);
                $sum += (int) $r['commission_minor'];
            }

            return $sum;
        });
    }

    public function setStatus(int $storeId, int $affiliateId, bool $active): void
    {
        $this->db->executeStatement('UPDATE mc_affiliate SET status=? WHERE id=? AND store_id=?', [$active ? 'active' : 'paused', $affiliateId, $storeId]);
    }
}
