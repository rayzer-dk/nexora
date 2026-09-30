<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

use Commerce\Modules\Seo\Http\SitemapController;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * IndexNow: tells Bing, Yandex, Seznam and other participating engines about new and changed URLs right away
 * instead of waiting for a crawl. The key is derived from the application secret and the host, so there is
 * nothing to store or leak; ownership is proven by serving the key at /{key}.txt.
 */
final readonly class IndexNowService
{
    private const ENDPOINT = 'https://api.indexnow.org/IndexNow';
    private const BATCH = 5000;

    public function __construct(
        private Connection $db,
        private HttpClientInterface $http,
        private string $publicBaseUrl,
        private string $secret,
    ) {
    }

    public function host(): string
    {
        return strtolower((string) (parse_url($this->publicBaseUrl, PHP_URL_HOST) ?: ''));
    }

    public function key(): string
    {
        return substr(hash_hmac('sha256', 'indexnow:' . $this->host(), $this->secret), 0, 32);
    }

    public function keyLocation(): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/' . $this->key() . '.txt';
    }

    /** IndexNow accepts only public https hosts. */
    public function usable(): bool
    {
        $host = $this->host();

        return str_starts_with(strtolower($this->publicBaseUrl), 'https://') && $host !== '' && $host !== 'localhost' && !str_ends_with($host, '.local') && filter_var($host, FILTER_VALIDATE_IP) === false;
    }

    /** @return array{enabled:bool,last_run_at:?string,last_status:?string,last_count:int} */
    public function state(int $storeId): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT enabled,last_run_at,last_status,last_count FROM mc_indexnow_state WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            $row = false;
        }

        return is_array($row)
            ? ['enabled' => (bool) $row['enabled'], 'last_run_at' => $row['last_run_at'] !== null ? (string) $row['last_run_at'] : null, 'last_status' => $row['last_status'] !== null ? (string) $row['last_status'] : null, 'last_count' => (int) $row['last_count']]
            : ['enabled' => false, 'last_run_at' => null, 'last_status' => null, 'last_count' => 0];
    }

    public function setEnabled(int $storeId, bool $enabled): void
    {
        $now = $this->now();
        $this->db->executeStatement('INSERT INTO mc_indexnow_state (store_id,enabled,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=VALUES(updated_at)', [$storeId, $enabled ? 1 : 0, $now]);
    }

    /** @return list<int> */
    public function enabledStores(): array
    {
        try {
            return array_map('intval', $this->db->fetchFirstColumn('SELECT store_id FROM mc_indexnow_state WHERE enabled=1'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Absolute URLs of indexable pages changed after $since (all of them when null).
     *
     * @return list<string>
     */
    public function changedUrls(int $storeId, ?string $since, int $limit = 10000): array
    {
        $lastmod = 'COALESCE(p.updated_at,c.updated_at,b.updated_at,ce.updated_at,sr.updated_at)';
        $rows = $this->db->fetchFirstColumn(
            "SELECT sr.path FROM mc_seo_route sr
             LEFT JOIN mc_product p ON sr.entity_type='product' AND p.public_id=sr.entity_public_id
             LEFT JOIN mc_category c ON sr.entity_type='category' AND c.public_id=sr.entity_public_id
             LEFT JOIN mc_brand b ON sr.entity_type='brand' AND b.public_id=sr.entity_public_id
             LEFT JOIN mc_content_entry ce ON sr.entity_type IN ('cms_page','blog_article','landing_page') AND ce.public_id=sr.entity_public_id
             WHERE sr.store_id=? AND sr.indexable=1 AND " . SitemapController::VISIBLE_ARTICLE . ($since !== null ? " AND {$lastmod} > ?" : '') . ' ORDER BY sr.id LIMIT ' . max(1, $limit),
            $since !== null ? [$storeId, $since] : [$storeId],
        );
        $base = rtrim($this->publicBaseUrl, '/');

        return array_values(array_unique(array_map(static fn ($path): string => $base . '/' . ltrim((string) $path, '/'), $rows)));
    }

    /** @return array{count:int,status:string,ok:bool} */
    public function submit(int $storeId, bool $all = false): array
    {
        if (!$this->usable()) {
            return ['count' => 0, 'status' => 'not_public_https', 'ok' => false];
        }
        $state = $this->state($storeId);
        $since = $all ? null : $state['last_run_at'];
        $started = $this->now();
        $urls = $this->changedUrls($storeId, $since);
        $sent = 0;
        $status = 'ok';
        foreach (array_chunk($urls, self::BATCH) as $chunk) {
            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'json' => ['host' => $this->host(), 'key' => $this->key(), 'keyLocation' => $this->keyLocation(), 'urlList' => $chunk],
                    'timeout' => 10,
                    'max_duration' => 20,
                ]);
                $code = $response->getStatusCode();
            } catch (\Throwable) {
                $status = 'network_error';
                break;
            }
            if ($code !== 200 && $code !== 202) {
                $status = 'http_' . $code;
                break;
            }
            $sent += count($chunk);
        }
        $ok = $status === 'ok';
        $this->db->executeStatement(
            'INSERT INTO mc_indexnow_state (store_id,enabled,last_run_at,last_status,last_count,updated_at) VALUES (?,0,?,?,?,?) ON DUPLICATE KEY UPDATE last_run_at=IF(?=1,VALUES(last_run_at),last_run_at),last_status=VALUES(last_status),last_count=VALUES(last_count),updated_at=VALUES(updated_at)',
            [$storeId, $ok ? $started : null, $status, $sent, $started, $ok ? 1 : 0],
        );

        return ['count' => $sent, 'status' => $status, 'ok' => $ok];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
