<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Core\Configuration\SystemSettingStore;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;

/**
 * Which events of the shop are sent to the owner's Telegram chat. A new order is on from the start (as before); a new request from a
 * customer and a failed payment are the next most urgent and on by default; the rest are opt-in so the chat stays readable.
 */
final class TelegramAlertSettings
{
    public const EVENTS = ['order_created' => true, 'inquiry' => true, 'payment_failed' => true, 'payment_paid' => false, 'order_cancelled' => false, 'review' => false];
    private const KEY = 'notification.telegram_alerts';

    public function __construct(private readonly SystemSettingStore $store, private readonly NotificationOutbox $outbox)
    {
    }

    /** @return array{events:array<string,bool>,links:bool,contacts:bool} */
    public function all(): array
    {
        try {
            $raw = $this->store->getArray(self::KEY) ?? [];
        } catch (\Throwable) {
            $raw = [];
        }
        $events = [];
        foreach (self::EVENTS as $event => $default) {
            $events[$event] = (bool) (($raw['events'][$event] ?? $default));
        }

        return ['events' => $events, 'links' => (bool) ($raw['links'] ?? true), 'contacts' => (bool) ($raw['contacts'] ?? true)];
    }

    public function enabled(string $event): bool
    {
        return $this->all()['events'][$event] ?? false;
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $events = [];
        foreach (array_keys(self::EVENTS) as $event) {
            $events[$event] = !empty($input['events'][$event]);
        }
        $this->store->setArray(self::KEY, ['events' => $events, 'links' => !empty($input['links']), 'contacts' => !empty($input['contacts'])]);
    }

    /** Queues a Telegram message for the owner when this event is switched on; delivery problems never reach the caller. */
    public function alert(string $event, string $subject, string $text, string $dedupe): void
    {
        if (!$this->enabled($event)) {
            return;
        }
        try {
            $this->outbox->enqueue(NotificationChannel::Telegram, new NotificationMessage('admin.alert.' . $event, $subject, $text, [], 'generic'), '', null, $dedupe);
        } catch (\Throwable) {
            // an alert must never break the customer's request
        }
    }
}
