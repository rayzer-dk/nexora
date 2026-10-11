<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Messenger;

use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A shopper links Telegram or Viber to an order with a one-time token in the address of the bot (deep link). Once the messenger answers with that
 * token the chat is remembered for this order, and for the customer account when the order was made signed in. Never by phone number: a stranger could
 * type someone else's number at checkout and then receive that person's orders.
 */
final readonly class MessengerLinkService
{
    public const CHANNELS = ['telegram' => NotificationChannel::TelegramCustomer, 'viber' => NotificationChannel::Viber];

    public function __construct(private Connection $db, private MessengerSettings $settings, private NotificationOutbox $outbox)
    {
    }

    /** @return list<string> the channels the shop offers */
    public function available(): array
    {
        $out = [];
        if ($this->settings->telegramReady()) {
            $out[] = 'telegram';
        }
        if ($this->settings->viberReady()) {
            $out[] = 'viber';
        }

        return $out;
    }

    /** The address that opens the messenger with the token (created once per order and channel). */
    public function linkFor(string $orderPublicId, string $channel): ?string
    {
        if (!isset(self::CHANNELS[$channel]) || !in_array($channel, $this->available(), true)) {
            return null;
        }
        try {
            $binary = Uuid::fromString($orderPublicId)->toBinary();
        } catch (\Throwable) {
            return null;
        }
        $order = $this->db->fetchAssociative('SELECT id,store_id,customer_id FROM mc_sales_order WHERE public_id=? LIMIT 1', [$binary]);
        if (!is_array($order)) {
            return null;
        }
        $token = $this->db->fetchOne("SELECT token FROM mc_messenger_link WHERE order_id=? AND channel=? AND status='pending' LIMIT 1", [(int) $order['id'], $channel]);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(12));
            $this->db->insert('mc_messenger_link', [
                'store_id' => (int) $order['store_id'], 'order_id' => (int) $order['id'], 'customer_id' => $order['customer_id'] !== null ? (int) $order['customer_id'] : null,
                'channel' => $channel, 'token' => $token, 'status' => 'pending', 'created_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
        }

        return $this->settings->deepLink($channel, $token);
    }

    /** True when a token of a pending link is known; the chat becomes the order's messenger. @return array{order_id:int,order_public_id:string}|null */
    public function attach(string $channel, string $token, string $chatId, string $name): ?array
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $token) !== 1 || $chatId === '') {
            return null;
        }
        $link = $this->db->fetchAssociative("SELECT id,order_id FROM mc_messenger_link WHERE token=? AND channel=? AND status='pending' LIMIT 1", [$token, $channel]);
        if (!is_array($link)) {
            return null;
        }
        $this->db->update('mc_messenger_link', ['chat_id' => $chatId, 'display_name' => mb_substr($name, 0, 190), 'status' => 'active', 'linked_at' => gmdate('Y-m-d H:i:s.u')], ['id' => (int) $link['id']]);
        $public = $this->db->fetchOne('SELECT public_id FROM mc_sales_order WHERE id=?', [(int) $link['order_id']]);

        return ['order_id' => (int) $link['order_id'], 'order_public_id' => Uuid::fromBinary((string) $public)->toRfc4122()];
    }

    /** The customer stopped the bot or left the channel. */
    public function stop(string $channel, string $chatId): void
    {
        $this->db->executeStatement("UPDATE mc_messenger_link SET status='stopped' WHERE channel=? AND chat_id=?", [$channel, $chatId]);
    }

    /** @return list<array{channel:string,chat_id:string}> chats that follow this order (linked to it, or to the account that made it) */
    public function chatsForOrder(int $orderId): array
    {
        $customerId = $this->db->fetchOne('SELECT customer_id FROM mc_sales_order WHERE id=?', [$orderId]);
        $rows = $this->db->fetchAllAssociative(
            "SELECT DISTINCT channel,chat_id FROM mc_messenger_link WHERE status='active' AND chat_id IS NOT NULL AND (order_id=? OR (? IS NOT NULL AND customer_id=?))",
            [$orderId, $customerId === false ? null : (int) $customerId, $customerId === false ? 0 : (int) $customerId],
        );

        return array_map(static fn (array $r): array => ['channel' => (string) $r['channel'], 'chat_id' => (string) $r['chat_id']], $rows);
    }

    /** Puts a message for every messenger that follows the order into the outgoing queue. Returns how many. */
    public function notifyOrder(int $orderId, NotificationMessage $message, string $dedupe = ''): int
    {
        $count = 0;
        foreach ($this->chatsForOrder($orderId) as $chat) {
            $channel = self::CHANNELS[$chat['channel']] ?? null;
            if ($channel === null) {
                continue;
            }
            $this->outbox->enqueue($channel, $message, $chat['chat_id'], null, 'msgr:' . substr(hash('sha256', $chat['channel'] . '|' . $chat['chat_id'] . '|' . $orderId . '|' . $message->type . '|' . $message->text . $dedupe), 0, 40));
            ++$count;
        }

        return $count;
    }
}
