<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Bots;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Optional look-up of a visitor's address in public abuse lists (Stop Forum Spam needs no key, AbuseIPDB needs the shop's own key).
 * It is asked only when a form is submitted, never for page views, answers are remembered for a day, and any failure lets the
 * visitor through: reputation is an extra signal, not a gate that can lock customers out.
 */
final class IpReputation
{
    public function __construct(
        private readonly BotProtection $bots,
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /** "stopforumspam" or "abuseipdb" when the address is known for abuse, null otherwise. */
    public function risky(string $ip): ?string
    {
        $config = $this->bots->config();
        if (!$config['enabled'] || (!$config['rep_sfs'] && $config['rep_abuse_key'] === '')
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        try {
            $item = $this->cache->getItem('ip_reputation_' . md5($ip . '|' . ($config['rep_sfs'] ? 1 : 0) . '|' . substr(md5($config['rep_abuse_key']), 0, 6)));
            if ($item->isHit()) {
                $hit = $item->get();

                return is_string($hit) && $hit !== '' ? $hit : null;
            }
            $verdict = $this->lookup($ip, $config);
            $item->set($verdict ?? '')->expiresAfter(86400);
            $this->cache->save($item);

            return $verdict;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{rep_sfs:bool,rep_abuse_key:string,rep_abuse_min:int} $config */
    private function lookup(string $ip, array $config): ?string
    {
        if ($config['rep_sfs']) {
            try {
                $data = $this->http->request('GET', 'https://api.stopforumspam.org/api', ['query' => ['ip' => $ip, 'json' => 1], 'timeout' => 2, 'max_duration' => 3])->toArray(false);
                $found = $data['ip'] ?? null;
                if (is_array($found) && (int) ($found['appears'] ?? 0) === 1 && ((int) ($found['frequency'] ?? 0) >= 3 || (float) ($found['confidence'] ?? 0) >= 50.0)) {
                    return 'stopforumspam';
                }
            } catch (\Throwable) {
                // fail open
            }
        }
        if ($config['rep_abuse_key'] !== '') {
            try {
                $data = $this->http->request('GET', 'https://api.abuseipdb.com/api/v2/check', ['query' => ['ipAddress' => $ip, 'maxAgeInDays' => 90], 'headers' => ['Key' => $config['rep_abuse_key'], 'Accept' => 'application/json'], 'timeout' => 2, 'max_duration' => 3])->toArray(false);
                if ((int) ($data['data']['abuseConfidenceScore'] ?? 0) >= $config['rep_abuse_min']) {
                    return 'abuseipdb';
                }
            } catch (\Throwable) {
                // fail open
            }
        }

        return null;
    }
}
