<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Infrastructure;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

/**
 * Third-party live chat (Tawk.to, Jivo, Crisp, Chatwoot). Only fixed, validated loaders are supported — no free-form
 * script injection — and the storefront loads the vendor script only after the visitor allowed the "preferences" cookies.
 */
final readonly class ChatWidgetSettings
{
    public const PROVIDERS = ['tawk', 'jivo', 'crisp', 'chatwoot'];

    /** @var array<string,array{script:list<string>,connect:list<string>,frame:list<string>,style:list<string>,font:list<string>}> */
    private const HOSTS = [
        'tawk' => [
            'script' => ['https://embed.tawk.to', 'https://*.tawk.to', 'https://cdn.jsdelivr.net'],
            'connect' => ['https://*.tawk.to', 'wss://*.tawk.to', 'https://cdn.jsdelivr.net'],
            'frame' => ['https://*.tawk.to'],
            'style' => ['https://*.tawk.to', 'https://fonts.googleapis.com', 'https://cdn.jsdelivr.net'],
            'font' => ['https://*.tawk.to', 'https://fonts.gstatic.com', 'https://cdn.jsdelivr.net'],
        ],
        'jivo' => [
            'script' => ['https://code.jivosite.com', 'https://*.jivosite.com', 'https://*.jivo.ru'],
            'connect' => ['https://*.jivosite.com', 'wss://*.jivosite.com', 'https://*.jivo.ru', 'wss://*.jivo.ru'],
            'frame' => ['https://*.jivosite.com', 'https://*.jivo.ru'],
            'style' => ['https://*.jivosite.com', 'https://*.jivo.ru'],
            'font' => ['https://*.jivosite.com', 'https://*.jivo.ru'],
        ],
        'crisp' => [
            'script' => ['https://client.crisp.chat', 'https://*.crisp.chat'],
            'connect' => ['https://*.crisp.chat', 'wss://*.crisp.chat'],
            'frame' => ['https://*.crisp.chat'],
            'style' => ['https://client.crisp.chat'],
            'font' => ['https://client.crisp.chat'],
        ],
    ];

    public function __construct(private Connection $db)
    {
    }

    /** @return array{provider:string,widget_id:string,base_url:string} */
    public function get(int $storeId): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT provider,widget_id,base_url FROM mc_chat_widget WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            $row = false;
        }
        if (!is_array($row) || !in_array((string) $row['provider'], self::PROVIDERS, true)) {
            return ['provider' => 'none', 'widget_id' => '', 'base_url' => ''];
        }

        return ['provider' => (string) $row['provider'], 'widget_id' => (string) $row['widget_id'], 'base_url' => (string) ($row['base_url'] ?? '')];
    }

    /**
     * @return array{provider:string,id:string,base:string,csp:array<string,list<string>>}|null
     */
    public function storefront(int $storeId): ?array
    {
        $s = $this->get($storeId);
        if ($s['provider'] === 'none') {
            return null;
        }

        return ['provider' => $s['provider'], 'id' => $s['widget_id'], 'base' => $s['base_url'], 'csp' => self::csp($s['provider'], $s['base_url'])];
    }

    /** @return array<string,list<string>> extra CSP sources per directive */
    public static function csp(string $provider, string $base = ''): array
    {
        if ($provider === 'chatwoot') {
            $host = strtolower((string) parse_url($base, PHP_URL_HOST));
            if ($host === '') {
                return [];
            }
            $https = 'https://' . $host;
            $wss = 'wss://' . $host;

            return ['script' => [$https], 'connect' => [$https, $wss], 'frame' => [$https], 'style' => [$https], 'font' => [$https]];
        }

        return self::HOSTS[$provider] ?? [];
    }

    /** Saves the provider from a pasted embed code or a bare ID; provider "none" switches the chat off. */
    public function save(int $storeId, string $provider, string $embed, string $baseUrl = ''): void
    {
        if ($provider === 'none') {
            $this->db->delete('mc_chat_widget', ['store_id' => $storeId]);

            return;
        }
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new \InvalidArgumentException(CanonicalUiText::get('admin.chat.error.provider'));
        }
        $current = $this->get($storeId);
        if (trim($embed) === '' && $current['provider'] === $provider) {
            $embed = $current['widget_id']; // saving the form without a new code keeps what is connected
            $baseUrl = $baseUrl !== '' ? $baseUrl : $current['base_url'];
        }
        $base = '';
        $id = self::extractId($provider, $embed);
        if ($provider === 'chatwoot') {
            $base = self::extractChatwootBase($baseUrl !== '' ? $baseUrl : $embed);
            if ($base === null) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.chat.error.base_url'));
            }
        }
        if ($id === null) {
            throw new \InvalidArgumentException(match ($provider) {
                'tawk' => CanonicalUiText::get('admin.chat.error.id_tawk'),
                'jivo' => CanonicalUiText::get('admin.chat.error.id_jivo'),
                'crisp' => CanonicalUiText::get('admin.chat.error.id_crisp'),
                default => CanonicalUiText::get('admin.chat.error.id_chatwoot'),
            });
        }
        $row = ['provider' => $provider, 'widget_id' => $id, 'base_url' => $base !== '' ? $base : null, 'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')];
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_chat_widget WHERE store_id=?', [$storeId]) > 0) {
            $this->db->update('mc_chat_widget', $row, ['store_id' => $storeId]);
        } else {
            $this->db->insert('mc_chat_widget', ['store_id' => $storeId] + $row);
        }
    }

    /** Accepts the vendor's pasted snippet or the bare identifier; returns null when nothing valid is found. */
    public static function extractId(string $provider, string $input): ?string
    {
        $input = trim($input);
        $patterns = match ($provider) {
            'tawk' => ['~embed\.tawk\.to/([a-f0-9]{20,30}/[A-Za-z0-9]{1,20})~', '~^([a-f0-9]{20,30}/[A-Za-z0-9]{1,20})$~'],
            'jivo' => ['~jivo(?:site\.com|\.ru|\.chat)/widget/([A-Za-z0-9]{6,20})~', '~^([A-Za-z0-9]{6,20})$~'],
            'crisp' => ['~CRISP_WEBSITE_ID\s*=\s*["\']([0-9a-fA-F-]{36})["\']~', '~^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$~'],
            'chatwoot' => ['~websiteToken\s*:\s*["\']([A-Za-z0-9]{10,40})["\']~', '~^([A-Za-z0-9]{10,40})$~'],
            default => [],
        };
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $m) === 1) {
                return strtolower($provider) === 'crisp' ? strtolower($m[1]) : $m[1];
            }
        }

        return null;
    }

    /** Origin (https only) of a Chatwoot installation, taken from a URL or from the pasted BASE_URL snippet. */
    public static function extractChatwootBase(string $input): ?string
    {
        if (preg_match('~BASE_URL\s*=\s*["\'](https://[^"\']+)["\']~', $input, $m) === 1) {
            $input = $m[1];
        }
        $parts = parse_url(trim($input));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1 || !str_contains($host, '.')) {
            return null;
        }

        return 'https://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
