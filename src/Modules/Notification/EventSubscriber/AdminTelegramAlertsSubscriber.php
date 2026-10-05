<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\TelegramAlertSettings;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Payment and cancellation alerts for the owner's Telegram chat. */
final readonly class AdminTelegramAlertsSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(private Connection $connection, private TelegramAlertSettings $alerts)
    {
    }

    public function subscriberId(): string
    {
        return 'core.notification.admin_telegram_alerts.v1';
    }

    public function subscribedEvents(): array
    {
        return [EventNames::PAYMENT_STATUS_CHANGED, EventNames::ORDER_CANCELLED];
    }

    public function handle(StoredDomainEvent $event): void
    {
        if ($event->eventName === EventNames::ORDER_CANCELLED) {
            $kind = 'order_cancelled';
        } else {
            $status = (string) ($event->payload['status'] ?? '');
            $kind = match ($status) {
                'paid', 'captured', 'succeeded' => 'payment_paid',
                'failed', 'declined', 'expired', 'cancelled' => 'payment_failed',
                default => '',
            };
        }
        if ($kind === '' || !$this->alerts->enabled($kind)) {
            return;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT order_number,total_minor,currency,customer_name,customer_phone FROM mc_sales_order WHERE public_id=? LIMIT 1',
            [Uuid::fromString($event->aggregateId)->toBinary()],
        );
        if (!is_array($row)) {
            return;
        }
        $settings = $this->alerts->all();
        $lines = [CanonicalUiText::get('notify.tg.order_line', ['number' => (string) $row['order_number'], 'total' => number_format((int) $row['total_minor'] / 100, 2, '.', ' ') . ' ' . $row['currency']])];
        if ($settings['contacts']) {
            $lines[] = trim((string) $row['customer_name'] . ' ' . (string) ($row['customer_phone'] ?? ''));
        }
        $reason = (string) ($event->payload['reason'] ?? '');
        if ($kind === 'order_cancelled' && $reason !== '') {
            $lines[] = $reason;
        }
        $this->alerts->alert($kind, CanonicalUiText::get('notify.tg.' . $kind), implode("\n", array_filter($lines)), 'event:' . $event->eventId . ':tg-alert');
    }
}
