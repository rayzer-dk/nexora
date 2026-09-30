<?php

declare(strict_types=1);

namespace Commerce\Modules\Analytics\Visit;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * First-party, cookie-free visit statistics.
 *
 * A visitor is a keyed hash of (day, IP, user agent): the hash rotates every day and cannot be reversed, so nothing
 * that identifies a person is stored and no consent is needed. A session ends after 30 minutes of silence. No
 * per-pageview rows exist: a page view is one counter increment per (day, path), so the data stays small.
 */
final class VisitTracker
{
    public const SESSION_TIMEOUT = 1800;
    private const BOT = '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|gtmetrix|curl|wget|python|java\/|go-http|monitor|uptime|preview|facebookexternalhit|whatsapp|telegrambot|scrapy|httpclient|okhttp|axios|node-fetch|postman|libwww|phantom|playwright/i';
    private const SKIP_PREFIX = ['/admin', '/api', '/build', '/media', '/captcha', '/push', '/account', '/cart/', '/checkout/place', '/checkout/promotion', '/newsletter', '/downloads/', '/_', '/feeds', '/sitemap', '/robots', '/manifest', '/nexora-'];
    private const SEARCH = ['google', 'bing', 'yahoo', 'duckduckgo', 'yandex', 'ecosia', 'baidu', 'brave', 'startpage'];
    private const SOCIAL = ['facebook', 'instagram', 't.co', 'twitter', 'x.com', 'tiktok', 'linkedin', 'youtube', 'pinterest', 'telegram', 't.me', 'reddit', 'viber', 'whatsapp'];
    private const MAX_PATHS_PER_DAY = 3000;

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    /** @return array{enabled:bool,retention_days:int} */
    public function settings(int $storeId): array
    {
        $row = $this->db->fetchAssociative('SELECT enabled,retention_days FROM mc_analytics_settings WHERE store_id=?', [$storeId]);

        return $row === false ? ['enabled' => true, 'retention_days' => 400] : ['enabled' => (bool) $row['enabled'], 'retention_days' => (int) $row['retention_days']];
    }

    public function saveSettings(int $storeId, bool $enabled, int $retentionDays): void
    {
        $this->db->executeStatement(
            'INSERT INTO mc_analytics_settings (store_id,enabled,retention_days,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),retention_days=VALUES(retention_days),updated_at=VALUES(updated_at)',
            [$storeId, $enabled ? 1 : 0, max(30, min(1095, $retentionDays))],
        );
    }

    /** True when this request is a countable storefront page view (or a cart / order event). */
    public function countable(Request $request, int $status, string $contentType): bool
    {
        $ua = (string) $request->headers->get('User-Agent', '');
        if ($ua === '' || preg_match(self::BOT, $ua) === 1) {
            return false;
        }
        if ($request->headers->get('Sec-Purpose') !== null || $request->headers->get('Purpose') !== null || $request->headers->get('X-Moz') === 'prefetch') {
            return false;
        }
        if ($request->getMethod() === 'POST' && $request->getPathInfo() === '/cart/add') {
            return $status === 200;
        }
        if ($request->getMethod() !== 'GET' || $status !== 200 || $request->isXmlHttpRequest() || !str_contains($contentType, 'text/html')) {
            return false;
        }
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/checkout/success/')) {
            return true;
        }
        foreach (self::SKIP_PREFIX as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    public function record(Request $request, int $storeId): void
    {
        $now = time();
        $path = $this->normalisePath($request);
        $hash = $this->visitorHash($request, gmdate('Y-m-d', $now));
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $page = (string) $request->attributes->get('_analytics_page', '');
        $isCartAdd = $request->getMethod() === 'POST';
        $isSuccess = str_starts_with($request->getPathInfo(), '/checkout/success/');

        $sessionId = (int) $this->db->fetchOne(
            'SELECT id FROM mc_analytics_session WHERE store_id=? AND visitor_hash=? AND last_seen_at>=? ORDER BY last_seen_at DESC LIMIT 1',
            [$storeId, $hash, gmdate('Y-m-d H:i:s', $now - self::SESSION_TIMEOUT)],
        );

        if ($isCartAdd) {
            if ($sessionId > 0) {
                $this->db->executeStatement('UPDATE mc_analytics_session SET added_to_cart=1,last_seen_at=? WHERE id=?', [$stamp, $sessionId]);
            }

            return;
        }

        $flags = '';
        if ($page === 'product') {
            $flags .= ',viewed_product=1';
        }
        if ($path === '/checkout') {
            $flags .= ',started_checkout=1';
        }
        if ($isSuccess) {
            $flags .= ',ordered=1,started_checkout=1';
        }

        if ($sessionId > 0) {
            $this->db->executeStatement('UPDATE mc_analytics_session SET pageviews=pageviews+1,last_seen_at=?' . $flags . ' WHERE id=?', [$stamp, $sessionId]);
            $entrance = 0;
        } else {
            [$source, $medium, $campaign, $referrerHost] = $this->attribution($request);
            $this->db->insert('mc_analytics_session', [
                'store_id' => $storeId, 'visitor_hash' => $hash, 'started_at' => $stamp, 'last_seen_at' => $stamp, 'pageviews' => 1,
                'landing_path' => $path, 'referrer_host' => $referrerHost, 'source' => $source, 'medium' => $medium, 'campaign' => $campaign,
                'device' => $this->device((string) $request->headers->get('User-Agent', '')),
                'viewed_product' => $page === 'product' ? 1 : 0, 'started_checkout' => ($path === '/checkout' || $isSuccess) ? 1 : 0, 'ordered' => $isSuccess ? 1 : 0,
            ]);
            $entrance = 1;
        }

        $day = gmdate('Y-m-d', $now);
        $key = $isSuccess ? '/checkout/success' : $path;
        if (!$this->pathAllowed($storeId, $day, $key)) {
            $key = '(other)';
        }
        $this->db->executeStatement(
            'INSERT INTO mc_analytics_page_daily (store_id,day,path,views,entrances) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE views=views+1,entrances=entrances+VALUES(entrances)',
            [$storeId, $day, $key, $entrance],
        );
    }

    /** Keeps the per-day path list bounded even if a bug or an attacker generates endless distinct 200 URLs. */
    private function pathAllowed(int $storeId, string $day, string $path): bool
    {
        if ($this->db->fetchOne('SELECT 1 FROM mc_analytics_page_daily WHERE store_id=? AND day=? AND path=?', [$storeId, $day, $path]) !== false) {
            return true;
        }

        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_analytics_page_daily WHERE store_id=? AND day=?', [$storeId, $day]) < self::MAX_PATHS_PER_DAY;
    }

    private function normalisePath(Request $request): string
    {
        $path = '/' . trim(rawurldecode($request->getPathInfo()), '/');

        return mb_substr($path === '' ? '/' : $path, 0, 190);
    }

    private function visitorHash(Request $request, string $day): string
    {
        $ip = (string) $request->getClientIp();
        $ua = mb_substr((string) $request->headers->get('User-Agent', ''), 0, 300);

        return substr(hash_hmac('sha256', $day . '|' . $ip . '|' . $ua, $this->secret . '|visit', true), 0, 16);
    }

    private function device(string $ua): string
    {
        if (preg_match('/ipad|tablet|kindle|silk/i', $ua) === 1) {
            return 'tablet';
        }

        return preg_match('/mobi|iphone|android/i', $ua) === 1 ? 'mobile' : 'desktop';
    }

    /** @return array{0:string,1:string,2:string,3:string} source, medium, campaign, referrer host */
    private function attribution(Request $request): array
    {
        $query = $request->query;
        $campaign = mb_substr(trim((string) $query->get('utm_campaign', '')), 0, 120);
        $host = strtolower((string) parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? '';
        $own = preg_replace('/^www\./', '', strtolower($request->getHost())) ?? '';
        $host = $host === $own ? '' : mb_substr($host, 0, 120);

        $utmSource = strtolower(mb_substr(trim((string) $query->get('utm_source', '')), 0, 80));
        if ($utmSource !== '') {
            $medium = strtolower(mb_substr(trim((string) $query->get('utm_medium', '')), 0, 40));

            return [$utmSource, $medium !== '' ? $medium : 'campaign', $campaign, $host];
        }
        if ($query->has('gclid')) {
            return ['google', 'cpc', $campaign, $host];
        }
        if ($query->has('fbclid')) {
            return ['facebook', 'paid', $campaign, $host];
        }
        if ($host === '') {
            return ['direct', 'none', $campaign, ''];
        }
        foreach (self::SEARCH as $engine) {
            if (str_contains($host, $engine)) {
                return [$engine, 'organic', $campaign, $host];
            }
        }
        foreach (self::SOCIAL as $network) {
            if ($host === $network || str_contains($host, $network)) {
                return [$network, 'social', $campaign, $host];
            }
        }

        return [$host, 'referral', $campaign, $host];
    }
}
