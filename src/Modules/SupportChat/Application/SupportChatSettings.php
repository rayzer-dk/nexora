<?php

declare(strict_types=1);

namespace Commerce\Modules\SupportChat\Application;

use Commerce\Core\Security\SecretVault;
use Doctrine\DBAL\Connection;

/**
 * Per-store settings of the Telegram support chat. The bot token is encrypted at rest; the webhook secret is random and
 * proves that an incoming update really comes from Telegram.
 */
final readonly class SupportChatSettings
{
    private const CONTEXT = 'support_chat.telegram_token';

    public function __construct(private Connection $db, private SecretVault $vault)
    {
    }

    /** @return array{enabled:bool,site_chat_enabled:bool,direct_enabled:bool,bot_token:string,has_token:bool,bot_username:string,group_chat_id:string,webhook_secret:string,welcome_text:string,offline_text:string,load_delay_seconds:int,seen_chats:list<array{id:string,title:string,is_forum:bool}>} */
    public function get(int $storeId): array
    {
        try {
            $row = $this->db->fetchAssociative('SELECT * FROM mc_support_chat_settings WHERE store_id=?', [$storeId]);
        } catch (\Throwable) {
            $row = false;
        }
        $empty = ['enabled' => false, 'site_chat_enabled' => true, 'direct_enabled' => true, 'bot_token' => '', 'has_token' => false, 'bot_username' => '', 'group_chat_id' => '', 'webhook_secret' => '', 'welcome_text' => '', 'offline_text' => '', 'load_delay_seconds' => 3, 'seen_chats' => []];
        if (!is_array($row)) {
            return $empty;
        }
        $token = '';
        if ((string) ($row['bot_token_enc'] ?? '') !== '') {
            try {
                $token = $this->vault->decrypt((string) $row['bot_token_enc'], self::CONTEXT);
            } catch (\Throwable) {
                $token = '';
            }
        }
        $seen = json_decode((string) ($row['seen_chats'] ?? ''), true);

        return [
            'enabled' => (int) $row['enabled'] === 1,
            'site_chat_enabled' => (int) $row['site_chat_enabled'] === 1,
            'direct_enabled' => (int) $row['direct_enabled'] === 1,
            'bot_token' => $token,
            'has_token' => $token !== '',
            'bot_username' => (string) $row['bot_username'],
            'group_chat_id' => (string) $row['group_chat_id'],
            'webhook_secret' => (string) $row['webhook_secret'],
            'welcome_text' => (string) $row['welcome_text'],
            'offline_text' => (string) $row['offline_text'],
            'load_delay_seconds' => max(0, min(60, (int) ($row['load_delay_seconds'] ?? 3))),
            'seen_chats' => is_array($seen) ? array_values(array_filter($seen, 'is_array')) : [],
        ];
    }

    /** The chat is usable once it is switched on and both the token and the staff group are known. */
    public function isReady(int $storeId): bool
    {
        $s = $this->get($storeId);

        return $s['enabled'] && $s['has_token'] && $s['group_chat_id'] !== '' && $s['webhook_secret'] !== '';
    }

    /** @return array{store_id:int}|null the store whose webhook secret matches (several stores may use their own bot) */
    public function findByWebhookSecret(string $secret): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $secret)) {
            return null;
        }
        try {
            $id = $this->db->fetchOne('SELECT store_id FROM mc_support_chat_settings WHERE webhook_secret=?', [$secret]);
        } catch (\Throwable) {
            return null;
        }

        return $id === false ? null : ['store_id' => (int) $id];
    }

    /** @param array<string,mixed> $input */
    public function save(int $storeId, array $input): void
    {
        $current = $this->get($storeId);
        $token = trim((string) ($input['bot_token'] ?? ''));
        if ($token !== '' && !preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/D', $token)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.support_chat.bad_token'));
        }
        $token = $token !== '' ? $token : $current['bot_token'];
        $group = trim((string) ($input['group_chat_id'] ?? $current['group_chat_id']));
        if ($group !== '' && !preg_match('/^-?\d{5,20}$/D', $group)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.support_chat.bad_group'));
        }
        $secret = $current['webhook_secret'] !== '' ? $current['webhook_secret'] : bin2hex(random_bytes(24));
        $row = [
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'site_chat_enabled' => !empty($input['site_chat_enabled']) ? 1 : 0,
            'direct_enabled' => !empty($input['direct_enabled']) ? 1 : 0,
            'bot_token_enc' => $token !== '' ? $this->vault->encrypt($token, self::CONTEXT) : null,
            'bot_username' => mb_substr(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($input['bot_username'] ?? $current['bot_username'])) ?? '', 0, 64),
            'group_chat_id' => $group,
            'webhook_secret' => $secret,
            'welcome_text' => mb_substr(trim(strip_tags((string) ($input['welcome_text'] ?? ''))), 0, 500),
            'offline_text' => mb_substr(trim(strip_tags((string) ($input['offline_text'] ?? ''))), 0, 500),
            'load_delay_seconds' => max(0, min(60, (int) ($input['load_delay_seconds'] ?? $current['load_delay_seconds']))),
            'seen_chats' => json_encode($current['seen_chats'], JSON_THROW_ON_ERROR),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $this->upsert($storeId, $row);
    }

    /** Remembers a group the bot has been seen in, so the admin can pick it instead of typing a numeric id. */
    public function rememberChat(int $storeId, string $id, string $title, bool $isForum): void
    {
        $current = $this->get($storeId);
        $seen = array_values(array_filter($current['seen_chats'], static fn (array $c): bool => (string) ($c['id'] ?? '') !== $id));
        array_unshift($seen, ['id' => $id, 'title' => mb_substr($title, 0, 120), 'is_forum' => $isForum]);
        $this->db->update('mc_support_chat_settings', ['seen_chats' => json_encode(array_slice($seen, 0, 10), JSON_THROW_ON_ERROR)], ['store_id' => $storeId]);
    }

    public function setBotUsername(int $storeId, string $username): void
    {
        $this->db->update('mc_support_chat_settings', ['bot_username' => mb_substr($username, 0, 64)], ['store_id' => $storeId]);
    }

    /** @param array<string,mixed> $row */
    private function upsert(int $storeId, array $row): void
    {
        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_support_chat_settings WHERE store_id=?', [$storeId]) > 0) {
            $this->db->update('mc_support_chat_settings', $row, ['store_id' => $storeId]);

            return;
        }
        $this->db->insert('mc_support_chat_settings', ['store_id' => $storeId] + $row);
    }
}
