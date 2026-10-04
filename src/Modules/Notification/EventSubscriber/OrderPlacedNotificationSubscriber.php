<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\EventSubscriber;

use Commerce\Core\Event\DomainEventSubscriberInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Notification failures are isolated by the domain-event delivery ledger and never
 * roll back a committed order. Re-delivery is safe because NotificationOutbox is
 * only reached after the subscriber delivery is claimed by a stable subscriber id.
 */
final readonly class OrderPlacedNotificationSubscriber implements DomainEventSubscriberInterface
{
    public function __construct(
        private Connection $connection,
        private NotificationOutbox $notifications,
        private StorefrontUiTranslator $translator,
        private \Commerce\Modules\Order\Application\OrderMethodPresenter $methodLabels,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%commerce.app_public_url%')] private string $publicUrl = '',
    ) {
    }

    public function subscriberId(): string
    {
        return 'core.notification.order_placed.v1';
    }

    public function subscribedEvents(): array
    {
        return [EventNames::ORDER_PLACED];
    }

    public function handle(StoredDomainEvent $event): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id,public_id,order_number,subtotal_minor,discount_minor,total_minor,currency,customer_email,customer_phone,customer_name,locale FROM mc_sales_order WHERE public_id=? LIMIT 1',
            [Uuid::fromString($event->aggregateId)->toBinary()],
        );
        if (!is_array($row)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.673a97ad6d36'));
        }

        $items = $this->connection->fetchAllAssociative('SELECT name,sku,quantity,unit_code,unit_price_minor,line_total_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id', [(int) $row['id']]);
        $locale = trim((string) ($row['locale'] ?? '')) ?: 'en-US';
        $fulfillment = $this->connection->fetchAssociative('SELECT provider_code,destination_snapshot FROM mc_fulfillment WHERE order_id=? ORDER BY id DESC LIMIT 1', [(int) $row['id']]);
        $payment = $this->connection->fetchOne('SELECT provider_code FROM mc_payment WHERE order_id=? ORDER BY id DESC LIMIT 1', [(int) $row['id']]);
        $destination = is_array($fulfillment) ? json_decode((string) $fulfillment['destination_snapshot'], true) : null;
        $fulfillmentLabel = is_array($fulfillment) ? $this->methodLabels->deliverySummary((string) $fulfillment['provider_code'], is_array($destination) ? $destination : [], $locale) : '';
        $paymentLabel = is_string($payment) && $payment !== '' ? $this->methodLabels->payment($payment, $locale) : '';
        $message = new NotificationMessage(
            'order.created',
            $this->translator->translate('order_number', $locale, ['number' => (string) $row['order_number']]),
            $this->translator->translate('order_received_plain', $locale),
            [
                'locale' => $locale,
                'order_number' => (string) $row['order_number'],
                'customer_name' => (string) $row['customer_name'],
                'subtotal_minor' => (int) $row['subtotal_minor'],
                'discount_minor' => (int) $row['discount_minor'],
                'total_minor' => (int) $row['total_minor'],
                'currency' => (string) $row['currency'],
                'items' => $items,
                'fulfillment_label' => $fulfillmentLabel,
                'payment_label' => $paymentLabel,
                'action_url' => $this->publicUrl !== '' ? rtrim($this->publicUrl, '/') . '/account/orders/' . \Symfony\Component\Uid\Uuid::fromBinary((string) $row['public_id'])->toRfc4122() : '',
                'action_label' => $this->translator->translate('view_order', $locale),
            ],
            'order_created',
        );

        $email = trim((string) ($row['customer_email'] ?? ''));
        if ($email !== '') {
            $this->notifications->enqueue(NotificationChannel::Email, $message, $email, null, 'event:' . $event->eventId . ':order-created:email');
        }
        $this->notifications->enqueue(NotificationChannel::Telegram, $message, '', null, 'event:' . $event->eventId . ':order-created:telegram');
    }
}
