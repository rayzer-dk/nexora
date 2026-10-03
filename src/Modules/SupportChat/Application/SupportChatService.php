<?php

declare(strict_types=1);

namespace Commerce\Modules\SupportChat\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\SupportChat\Infrastructure\TelegramBotClient;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Support conversations. Every customer — a visitor of the website chat or someone writing to the bot in Telegram — gets
 * their own topic in the staff group; an answer written inside that topic goes back to exactly that customer.
 */
final class SupportChatService
{
    private const MAX_TEXT = 2000;

    public function __construct(
        private readonly Connection $db,
        private readonly SupportChatSettings $settings,
        private readonly TelegramBotClient $bot,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function threadByToken(int $storeId, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
            return null;
        }
        $row = $this->db->fetchAssociative("SELECT * FROM mc_support_thread WHERE store_id=? AND channel='web' AND public_token=?", [$storeId, $token]);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    public function createWebThread(int $storeId, string $name, string $contact, ?int $userId, string $sourceUrl): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('mc_support_thread', [
            'store_id' => $storeId,
            'channel' => 'web',
            'public_token' => bin2hex(random_bytes(16)),
            'customer_name' => mb_substr(trim(strip_tags($name)), 0, 120),
            'customer_contact' => mb_substr(trim(strip_tags($contact)), 0, 190),
            'customer_user_id' => $userId,
            'source_url' => mb_substr($sourceUrl, 0, 500),
            'status' => 'open',
            'created_at' => $now,
            'last_message_at' => $now,
        ]);

        return $this->db->fetchAssociative('SELECT * FROM mc_support_thread WHERE id=?', [(int) $this->db->lastInsertId()]) ?: [];
    }

    /** How many messages the visitor sent within the last ten minutes (a cheap flood guard). */
    public function recentVisitorMessages(int $threadId): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_support_message WHERE thread_id=? AND direction='in' AND created_at > ?", [$threadId, gmdate('Y-m-d H:i:s', time() - 600)]);
    }

    /** @return list<array{id:int,dir:string,body:string,at:string}> */
    public function messages(int $threadId, int $afterId = 0, int $limit = 80): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id,direction,body,created_at FROM mc_support_message WHERE thread_id=? AND id>? ORDER BY id ASC LIMIT ' . max(1, min(200, $limit)), [$threadId, $afterId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'dir' => (string) $r['direction'], 'body' => (string) $r['body'], 'at' => (string) $r['created_at']], $rows);
    }

    /**
     * A message typed on the website. It is always stored; the returned flag says whether Telegram took it.
     *
     * @param array<string,mixed> $thread
     * @return array{id:int,delivered:bool}
     */
    public function postFromVisitor(int $storeId, array $thread, string $text): array
    {
        $text = mb_substr(trim($text), 0, self::MAX_TEXT);
        $id = $this->store((int) $thread['id'], 'in', $text, null, null);
        $delivered = false;
        try {
            $s = $this->settings->get($storeId);
            $topic = $this->ensureTopic($s, $thread);
            $sent = $this->bot->call($s['bot_token'], 'sendMessage', ['chat_id' => $s['group_chat_id'], 'message_thread_id' => $topic, 'text' => $text]);
            $this->db->update('mc_support_message', ['tg_message_id' => (int) ($sent['message_id'] ?? 0) ?: null], ['id' => $id]);
            $delivered = true;
        } catch (\Throwable $e) {
            $this->logger->warning('Support chat: delivery to Telegram failed: ', ['exception' => $e]);
        }

        return ['id' => $id, 'delivered' => $delivered];
    }

    /** @param array<string,mixed> $update one Telegram update delivered to the webhook */
    public function handleUpdate(int $storeId, array $update): void
    {
        $s = $this->settings->get($storeId);
        if ($s['bot_token'] === '') {
            return;
        }
        $updateId = (int) ($update['update_id'] ?? 0);
        foreach (['message', 'my_chat_member'] as $key) {
            $chat = $update[$key]['chat'] ?? null;
            if (is_array($chat) && in_array((string) ($chat['type'] ?? ''), ['group', 'supergroup'], true)) {
                $this->settings->rememberChat($storeId, (string) $chat['id'], (string) ($chat['title'] ?? ''), (bool) ($chat['is_forum'] ?? false));
            }
        }
        $message = $update['message'] ?? null;
        if (!is_array($message) || !is_array($message['chat'] ?? null)) {
            return;
        }
        $chatType = (string) $message['chat']['type'];
        if ($chatType === 'private') {
            if ($s['direct_enabled'] && $s['enabled']) {
                $this->fromTelegramUser($storeId, $s, $message, $updateId);
            }

            return;
        }
        if ((string) $message['chat']['id'] === $s['group_chat_id'] && $s['enabled'] && !empty($message['is_topic_message']) && !($message['from']['is_bot'] ?? false)) {
            $this->fromStaff($storeId, $s, $message, $updateId);
        }
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $message
     */
    private function fromTelegramUser(int $storeId, array $s, array $message, int $updateId): void
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $userId = (int) ($from['id'] ?? 0);
        if ($userId <= 0 || ($from['is_bot'] ?? false)) {
            return;
        }
        $text = (string) ($message['text'] ?? '');
        $chatId = (string) $message['chat']['id'];
        if (preg_match('~^/start(?:@\w+)?(?:\s|$)~', $text) === 1) {
            $welcome = $s['welcome_text'] !== '' ? $s['welcome_text'] : CanonicalUiText::get('support_chat.tg_welcome');
            $this->safeSend($s, 'sendMessage', ['chat_id' => $chatId, 'text' => $welcome]);

            return;
        }
        $thread = $this->db->fetchAssociative("SELECT * FROM mc_support_thread WHERE store_id=? AND channel='telegram' AND telegram_user_id=? ORDER BY id DESC LIMIT 1", [$storeId, $userId]);
        if (!is_array($thread)) {
            $name = trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? ''));
            $now = gmdate('Y-m-d H:i:s');
            $this->db->insert('mc_support_thread', [
                'store_id' => $storeId, 'channel' => 'telegram', 'public_token' => bin2hex(random_bytes(16)), 'telegram_user_id' => $userId,
                'customer_name' => mb_substr($name !== '' ? $name : 'Telegram ' . $userId, 0, 120),
                'customer_contact' => isset($from['username']) ? '@' . mb_substr((string) $from['username'], 0, 60) : '',
                'status' => 'open', 'created_at' => $now, 'last_message_at' => $now,
            ]);
            $thread = $this->db->fetchAssociative('SELECT * FROM mc_support_thread WHERE id=?', [(int) $this->db->lastInsertId()]) ?: [];
        } elseif ((string) $thread['status'] !== 'open') {
            $this->db->update('mc_support_thread', ['status' => 'open'], ['id' => (int) $thread['id']]);
        }
        $body = $text !== '' ? $text : (string) ($message['caption'] ?? '');
        $stored = $this->store((int) $thread['id'], 'in', mb_substr($body !== '' ? $body : '[' . CanonicalUiText::get('support_chat.attachment') . ']', 0, self::MAX_TEXT), (int) ($message['message_id'] ?? 0) ?: null, $updateId ?: null);
        if ($stored === 0) {
            return; // an update Telegram delivered twice
        }
        try {
            $topic = $this->ensureTopic($s, $thread);
            if ($text !== '') {
                $this->bot->call($s['bot_token'], 'sendMessage', ['chat_id' => $s['group_chat_id'], 'message_thread_id' => $topic, 'text' => mb_substr($text, 0, 4000)]);
            } else {
                $this->bot->call($s['bot_token'], 'copyMessage', ['chat_id' => $s['group_chat_id'], 'message_thread_id' => $topic, 'from_chat_id' => $chatId, 'message_id' => (int) $message['message_id']]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Support chat: could not forward a Telegram message to the topic: ', ['exception' => $e]);
        }
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $message
     */
    private function fromStaff(int $storeId, array $s, array $message, int $updateId): void
    {
        $topic = (int) ($message['message_thread_id'] ?? 0);
        $thread = $topic > 0 ? $this->db->fetchAssociative('SELECT * FROM mc_support_thread WHERE store_id=? AND tg_topic_id=? ORDER BY id DESC LIMIT 1', [$storeId, $topic]) : false;
        if (!is_array($thread)) {
            return;
        }
        $text = (string) ($message['text'] ?? '');
        if (preg_match('~^/close(?:@\w+)?\s*$~', $text) === 1) {
            $this->db->update('mc_support_thread', ['status' => 'closed'], ['id' => (int) $thread['id']]);
            $this->safeSend($s, 'sendMessage', ['chat_id' => $s['group_chat_id'], 'message_thread_id' => $topic, 'text' => CanonicalUiText::get('support_chat.closed_note')]);

            return;
        }
        if (str_starts_with($text, '/')) {
            return;
        }
        $body = $text !== '' ? $text : (string) ($message['caption'] ?? '');
        $stored = $this->store((int) $thread['id'], 'out', mb_substr($body !== '' ? $body : '[' . CanonicalUiText::get('support_chat.attachment') . ']', 0, self::MAX_TEXT), (int) ($message['message_id'] ?? 0) ?: null, $updateId ?: null);
        if ($stored === 0 || (string) $thread['channel'] !== 'telegram') {
            return;
        }
        if ($text !== '') {
            $this->safeSend($s, 'sendMessage', ['chat_id' => (string) $thread['telegram_user_id'], 'text' => mb_substr($text, 0, 4000)]);
        } else {
            $this->safeSend($s, 'copyMessage', ['chat_id' => (string) $thread['telegram_user_id'], 'from_chat_id' => $s['group_chat_id'], 'message_id' => (int) $message['message_id']]);
        }
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $thread
     */
    private function ensureTopic(array $s, array $thread): int
    {
        $existing = (int) ($thread['tg_topic_id'] ?? 0);
        if ($existing > 0) {
            return $existing;
        }
        $label = trim((string) $thread['customer_name']) !== '' ? (string) $thread['customer_name'] : CanonicalUiText::get('support_chat.visitor');
        $topic = $this->bot->call($s['bot_token'], 'createForumTopic', ['chat_id' => $s['group_chat_id'], 'name' => mb_substr($label . ' · #' . (int) $thread['id'], 0, 120)]);
        $topicId = (int) ($topic['message_thread_id'] ?? 0);
        if ($topicId <= 0) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.support_chat.no_topic'));
        }
        $this->db->update('mc_support_thread', ['tg_topic_id' => $topicId], ['id' => (int) $thread['id']]);
        $lines = [CanonicalUiText::get('support_chat.new_chat') . ' #' . (int) $thread['id'] . ' (' . ($thread['channel'] === 'web' ? CanonicalUiText::get('support_chat.channel_web') : 'Telegram') . ')'];
        $lines[] = CanonicalUiText::get('support_chat.name') . ': ' . ($label);
        if ((string) $thread['customer_contact'] !== '') {
            $lines[] = CanonicalUiText::get('support_chat.contact') . ': ' . $thread['customer_contact'];
        }
        if ((string) $thread['source_url'] !== '') {
            $lines[] = CanonicalUiText::get('support_chat.page') . ': ' . $thread['source_url'];
        }
        $lines[] = CanonicalUiText::get('support_chat.reply_hint');
        $this->safeSend($s, 'sendMessage', ['chat_id' => $s['group_chat_id'], 'message_thread_id' => $topicId, 'text' => implode("\n", $lines), 'disable_web_page_preview' => true]);

        return $topicId;
    }

    /**
     * @param array<string,mixed> $s
     * @param array<string,mixed> $payload
     */
    private function safeSend(array $s, string $method, array $payload): void
    {
        try {
            $this->bot->call($s['bot_token'], $method, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('Support chat: a Telegram call failed.', ['method' => $method, 'exception' => $e]);
        }
    }

    /** @return int the new message id, 0 when this Telegram update was stored before */
    private function store(int $threadId, string $direction, string $body, ?int $tgMessageId, ?int $updateId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->db->insert('mc_support_message', ['thread_id' => $threadId, 'direction' => $direction, 'body' => $body, 'tg_message_id' => $tgMessageId, 'tg_update_id' => $updateId, 'created_at' => $now]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return 0;
        }
        $id = (int) $this->db->lastInsertId();
        $this->db->update('mc_support_thread', ['last_message_at' => $now], ['id' => $threadId]);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function recentThreads(int $storeId, int $limit = 30): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT t.id,t.channel,t.customer_name,t.customer_contact,t.status,t.last_message_at,t.created_at,
                    (SELECT COUNT(*) FROM mc_support_message m WHERE m.thread_id=t.id) AS messages
             FROM mc_support_thread t WHERE t.store_id=? ORDER BY t.last_message_at DESC LIMIT " . max(1, min(100, $limit)),
            [$storeId],
        );
    }
}
