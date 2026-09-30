<?php

declare(strict_types=1);

namespace Commerce\Modules\Push\Application;

use Commerce\Core\Security\OutboundUrlPolicy;
use Doctrine\DBAL\Connection;

final readonly class PushSubscriptionService
{
    public const AUDIENCES = ['admin', 'storefront'];
    private const MAX_PER_STORE = 50000;

    public function __construct(private Connection $db, private OutboundUrlPolicy $urls)
    {
    }

    public function subscribe(int $storeId, string $audience, string $endpoint, string $p256dh, string $auth, string $locale): void
    {
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new \InvalidArgumentException('audience');
        }
        $endpoint = trim($endpoint);
        if ($endpoint === '' || strlen($endpoint) > 1024) {
            throw new \InvalidArgumentException('endpoint');
        }
        $this->urls->assertPublicHttps($endpoint, false);
        $key = WebPushCrypto::b64uDecode($p256dh);
        $secret = WebPushCrypto::b64uDecode($auth);
        if (strlen($key) !== 65 || $key[0] !== "\x04" || strlen($secret) < 16 || strlen($secret) > 32) {
            throw new \InvalidArgumentException('keys');
        }
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_push_subscription WHERE store_id=?', [$storeId]) >= self::MAX_PER_STORE) {
            throw new \InvalidArgumentException('limit');
        }
        $this->db->executeStatement(
            'INSERT INTO mc_push_subscription (store_id,audience,endpoint_hash,endpoint,p256dh,auth,locale,failures,created_at) VALUES (?,?,?,?,?,?,?,0,?)
             ON DUPLICATE KEY UPDATE audience=VALUES(audience),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),auth=VALUES(auth),locale=VALUES(locale),failures=0',
            [$storeId, $audience, hash('sha256', $endpoint), $endpoint, WebPushCrypto::b64uEncode($key), WebPushCrypto::b64uEncode($secret), mb_substr($locale, 0, 16), gmdate('Y-m-d H:i:s.u')],
        );
    }

    public function unsubscribe(int $storeId, string $endpoint): void
    {
        $this->db->delete('mc_push_subscription', ['store_id' => $storeId, 'endpoint_hash' => hash('sha256', trim($endpoint))]);
    }

    /** @return array{admin:int,storefront:int} */
    public function counts(int $storeId): array
    {
        $rows = $this->db->fetchAllKeyValue('SELECT audience,COUNT(*) FROM mc_push_subscription WHERE store_id=? GROUP BY audience', [$storeId]);

        return ['admin' => (int) ($rows['admin'] ?? 0), 'storefront' => (int) ($rows['storefront'] ?? 0)];
    }

    /** @return list<array<string,mixed>> */
    public function forAudience(int $storeId, string $audience, int $limit): array
    {
        return $this->db->fetchAllAssociative('SELECT id,endpoint,p256dh,auth FROM mc_push_subscription WHERE store_id=? AND audience=? ORDER BY id LIMIT ' . max(1, min(5000, $limit)), [$storeId, $audience]);
    }

    public function success(int $id): void
    {
        $this->db->executeStatement('UPDATE mc_push_subscription SET failures=0,last_success_at=? WHERE id=?', [gmdate('Y-m-d H:i:s.u'), $id]);
    }

    /** Gone subscriptions are removed at once; transient failures are removed after five in a row. */
    public function failure(int $id, bool $gone): void
    {
        if ($gone) {
            $this->db->delete('mc_push_subscription', ['id' => $id]);

            return;
        }
        $this->db->executeStatement('UPDATE mc_push_subscription SET failures=failures+1 WHERE id=?', [$id]);
        $this->db->executeStatement('DELETE FROM mc_push_subscription WHERE id=? AND failures>=5', [$id]);
    }
}
