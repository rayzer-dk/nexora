<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Bots;

use Commerce\Core\Configuration\SystemSettingStore;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Blocks harmful or unwanted crawlers before the application does any work: vulnerability scanners, site copiers, SEO crawlers,
 * AI-training crawlers, requests without a User-Agent, and the shop's own lists of User-Agent words and IP addresses.
 * Everything is built in and offline; nothing is asked from an external service. Search engines (Google, Bing, Yandex...) are never in the lists.
 */
final class BotProtection
{
    /** @var array<string,array{default:bool,agents:list<string>}> */
    public const CATEGORIES = [
        'scanners' => ['default' => true, 'agents' => ['sqlmap', 'nikto', 'nmap', 'masscan', 'acunetix', 'nessus', 'wpscan', 'zgrab', 'dirbuster', 'gobuster', 'havij', 'w3af', 'netsparker', 'openvas', 'nuclei', 'hydra', 'jaeles', 'fimap', 'burpcollaborator']],
        'scrapers' => ['default' => true, 'agents' => ['httrack', 'webcopier', 'webzip', 'teleportpro', 'sitesnagger', 'offline explorer', 'emailcollector', 'emailsiphon', 'scrapebox', 'scrapy', 'extractorpro', 'webbandit']],
        'seo' => ['default' => false, 'agents' => ['ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'blexbot', 'dataforseobot', 'petalbot', 'serpstatbot', 'megaindex', 'seokicks']],
        'ai' => ['default' => false, 'agents' => ['gptbot', 'chatgpt-user', 'oai-searchbot', 'ccbot', 'claudebot', 'anthropic-ai', 'claude-web', 'bytespider', 'perplexitybot', 'amazonbot', 'applebot-extended', 'meta-externalagent', 'facebookbot', 'diffbot', 'imagesiftbot', 'omgilibot', 'cohere-ai', 'youbot']],
    ];

    /** Names of the crawlers of the "ai" group as they are written in robots.txt. */
    public const ROBOTS_AI = ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'CCBot', 'ClaudeBot', 'anthropic-ai', 'Claude-Web', 'Bytespider', 'PerplexityBot', 'Google-Extended', 'Applebot-Extended', 'Amazonbot', 'meta-externalagent', 'Diffbot', 'cohere-ai'];

    private const KEY = 'security.bots';
    private const CACHE = 'bot_protection_config';

    public function __construct(private readonly SystemSettingStore $store, private readonly CacheItemPoolInterface $cache)
    {
    }

    /** @return array{enabled:bool,categories:list<string>,empty_ua:bool,custom_agents:list<string>,ips:list<string>,robots_ai:bool} */
    public function config(): array
    {
        $item = $this->cache->getItem(self::CACHE);
        if ($item->isHit() && is_array($item->get())) {
            /** @var array{enabled:bool,categories:list<string>,empty_ua:bool,custom_agents:list<string>,ips:list<string>,robots_ai:bool} $hit */
            $hit = $item->get();

            return $hit;
        }
        try {
            $raw = $this->store->getArray(self::KEY) ?? [];
        } catch (\Throwable) {
            // the database may be down: a bot filter must never be the reason a page fails
            return ['enabled' => false, 'categories' => [], 'empty_ua' => false, 'custom_agents' => [], 'ips' => [], 'robots_ai' => false] + self::extras([]);
        }
        $categories = isset($raw['categories']) && is_array($raw['categories']) ? array_values(array_intersect(array_keys(self::CATEGORIES), array_map('strval', $raw['categories']))) : array_keys(array_filter(self::CATEGORIES, static fn (array $c): bool => $c['default']));
        $config = [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'categories' => $categories,
            'empty_ua' => (bool) ($raw['empty_ua'] ?? false),
            'custom_agents' => self::lines($raw['custom_agents'] ?? []),
            'ips' => array_values(array_filter(self::lines($raw['ips'] ?? []), static fn (string $ip): bool => self::validIp($ip))),
            'robots_ai' => (bool) ($raw['robots_ai'] ?? false),
        ] + self::extras($raw);
        $item->set($config)->expiresAfter(60);
        $this->cache->save($item);

        return $config;
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $this->store->setArray(self::KEY, [
            'enabled' => !empty($input['enabled']),
            'categories' => array_values(array_intersect(array_keys(self::CATEGORIES), array_map('strval', (array) ($input['categories'] ?? [])))),
            'empty_ua' => !empty($input['empty_ua']),
            'custom_agents' => self::lines((string) ($input['custom_agents'] ?? '')),
            'ips' => array_values(array_filter(self::lines((string) ($input['ips'] ?? '')), static fn (string $ip): bool => self::validIp($ip))),
            'robots_ai' => !empty($input['robots_ai']),
            'probe_enabled' => !empty($input['probe_enabled']),
            'probe_window' => max(1, min(120, (int) ($input['probe_window'] ?? 10))),
            'probe_max' => max(5, min(500, (int) ($input['probe_max'] ?? 20))),
            'probe_ban' => max(5, min(10080, (int) ($input['probe_ban'] ?? 60))),
            'rep_sfs' => !empty($input['rep_sfs']),
            'rep_abuse_key' => preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['rep_abuse_key'] ?? '')) ?? '',
            'rep_abuse_min' => max(10, min(100, (int) ($input['rep_abuse_min'] ?? 50))),
        ]);
        $this->cache->deleteItem(self::CACHE);
    }

    /** @return array{probe_enabled:bool,probe_window:int,probe_max:int,probe_ban:int,rep_sfs:bool,rep_abuse_key:string,rep_abuse_min:int} */
    private static function extras(array $raw): array
    {
        return [
            'probe_enabled' => (bool) ($raw['probe_enabled'] ?? true),
            'probe_window' => max(1, min(120, (int) ($raw['probe_window'] ?? 10))),
            'probe_max' => max(5, min(500, (int) ($raw['probe_max'] ?? 20))),
            'probe_ban' => max(5, min(10080, (int) ($raw['probe_ban'] ?? 60))),
            'rep_sfs' => (bool) ($raw['rep_sfs'] ?? false),
            'rep_abuse_key' => (string) ($raw['rep_abuse_key'] ?? ''),
            'rep_abuse_min' => max(10, min(100, (int) ($raw['rep_abuse_min'] ?? 50))),
        ];
    }

    /** Search engines are never counted as scanners, whatever they hit. */
    private const GOOD_BOTS = ['googlebot', 'bingbot', 'yandex', 'duckduckbot', 'baiduspider', 'applebot', 'facebookexternalhit', 'twitterbot', 'linkedinbot', 'slurp'];

    /**
     * Counts a "not found" answer for the client; a client that collects too many in a short window is a scanner looking for
     * admin panels and old backups, and is blocked for a while. Pages that never existed cost real visitors at most a handful of 404s.
     */
    public function recordNotFound(string $ip, string $userAgent): void
    {
        $config = $this->config();
        if (!$config['enabled'] || !$config['probe_enabled'] || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            // private and loopback addresses are the proxy or the developer, never a scanner worth banning
            return;
        }
        $ua = strtolower($userAgent);
        foreach (self::GOOD_BOTS as $good) {
            if (str_contains($ua, $good)) {
                return;
            }
        }
        try {
            $key = 'bot_probe_' . md5($ip);
            $item = $this->cache->getItem($key);
            $hits = $item->isHit() && is_array($item->get()) ? array_values(array_filter($item->get(), static fn (int $t): bool => $t > time() - $config['probe_window'] * 60)) : [];
            $hits[] = time();
            if (count($hits) >= $config['probe_max']) {
                $ban = $this->cache->getItem('bot_probe_ban_' . md5($ip));
                $ban->set(time())->expiresAfter($config['probe_ban'] * 60);
                $this->cache->save($ban);
                $this->rememberBan($ip, $config['probe_ban']);
                $this->cache->deleteItem($key);

                return;
            }
            $item->set($hits)->expiresAfter($config['probe_window'] * 60);
            $this->cache->save($item);
        } catch (\Throwable) {
            // a counter must never break a page
        }
    }

    public function probeBanned(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        try {
            return $this->cache->getItem('bot_probe_ban_' . md5($ip))->isHit();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array{ip:string,at:int,minutes:int}> */
    public function recentProbeBans(): array
    {
        try {
            $item = $this->cache->getItem('bot_probe_recent');

            return $item->isHit() && is_array($item->get()) ? $item->get() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function liftProbeBans(): void
    {
        foreach ($this->recentProbeBans() as $ban) {
            $this->cache->deleteItem('bot_probe_ban_' . md5($ban['ip']));
        }
        $this->cache->deleteItem('bot_probe_recent');
    }

    private function rememberBan(string $ip, int $minutes): void
    {
        $item = $this->cache->getItem('bot_probe_recent');
        $list = $item->isHit() && is_array($item->get()) ? $item->get() : [];
        array_unshift($list, ['ip' => $ip, 'at' => time(), 'minutes' => $minutes]);
        $item->set(array_slice($list, 0, 30))->expiresAfter(7 * 86400);
        $this->cache->save($item);
    }

    /** The reason a request is blocked ("scanners", "ai", "custom", "ip", "empty_ua") or null when it may pass. */
    public function verdict(string $userAgent, string $ip): ?string
    {
        $config = $this->config();
        if (!$config['enabled']) {
            return null;
        }

        if ($config['probe_enabled'] && $this->probeBanned($ip)) {
            return 'probe';
        }

        return $this->check($config, $userAgent, $ip);
    }

    /** Same check ignoring the on/off switch, to try a User-Agent on the settings page. */
    public function test(string $userAgent, string $ip = ''): ?string
    {
        return $this->check($this->config(), $userAgent, $ip);
    }

    public function count(string $reason): void
    {
        try {
            $item = $this->cache->getItem('bot_protection_hits_' . gmdate('Ymd'));
            $hits = $item->isHit() && is_array($item->get()) ? $item->get() : [];
            $hits[$reason] = (int) ($hits[$reason] ?? 0) + 1;
            $item->set($hits)->expiresAfter(8 * 86400);
            $this->cache->save($item);
        } catch (\Throwable) {
            // counters are a courtesy
        }
    }

    /** @return array<string,array<string,int>> day => reason => requests, newest first */
    public function stats(int $days = 7): array
    {
        $out = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = gmdate('Ymd', time() - $i * 86400);
            try {
                $item = $this->cache->getItem('bot_protection_hits_' . $day);
                $out[gmdate('Y-m-d', time() - $i * 86400)] = $item->isHit() && is_array($item->get()) ? $item->get() : [];
            } catch (\Throwable) {
                $out[gmdate('Y-m-d', time() - $i * 86400)] = [];
            }
        }

        return $out;
    }

    /** @param array{enabled:bool,categories:list<string>,empty_ua:bool,custom_agents:list<string>,ips:list<string>,robots_ai:bool} $config */
    private function check(array $config, string $userAgent, string $ip): ?string
    {
        if ($ip !== '' && $config['ips'] !== [] && IpUtils::checkIp($ip, $config['ips'])) {
            return 'ip';
        }
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return $config['empty_ua'] ? 'empty_ua' : null;
        }
        foreach ($config['categories'] as $category) {
            foreach (self::CATEGORIES[$category]['agents'] as $agent) {
                if (str_contains($ua, $agent)) {
                    return $category;
                }
            }
        }
        foreach ($config['custom_agents'] as $agent) {
            if (str_contains($ua, strtolower($agent))) {
                return 'custom';
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function lines(mixed $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/[\r\n,]+/', (string) $value) ?: []);
        $out = [];
        foreach ($items as $item) {
            $item = mb_substr(trim((string) $item), 0, 120);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return array_slice($out, 0, 500);
    }

    private static function validIp(string $ip): bool
    {
        $base = explode('/', $ip)[0];

        return filter_var($base, FILTER_VALIDATE_IP) !== false;
    }
}
