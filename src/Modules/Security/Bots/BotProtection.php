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
            return ['enabled' => false, 'categories' => [], 'empty_ua' => false, 'custom_agents' => [], 'ips' => [], 'robots_ai' => false];
        }
        $categories = isset($raw['categories']) && is_array($raw['categories']) ? array_values(array_intersect(array_keys(self::CATEGORIES), array_map('strval', $raw['categories']))) : array_keys(array_filter(self::CATEGORIES, static fn (array $c): bool => $c['default']));
        $config = [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'categories' => $categories,
            'empty_ua' => (bool) ($raw['empty_ua'] ?? false),
            'custom_agents' => self::lines($raw['custom_agents'] ?? []),
            'ips' => array_values(array_filter(self::lines($raw['ips'] ?? []), static fn (string $ip): bool => self::validIp($ip))),
            'robots_ai' => (bool) ($raw['robots_ai'] ?? false),
        ];
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
        ]);
        $this->cache->deleteItem(self::CACHE);
    }

    /** The reason a request is blocked ("scanners", "ai", "custom", "ip", "empty_ua") or null when it may pass. */
    public function verdict(string $userAgent, string $ip): ?string
    {
        $config = $this->config();
        if (!$config['enabled']) {
            return null;
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
