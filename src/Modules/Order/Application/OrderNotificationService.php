<?php

declare(strict_types=1);

namespace Commerce\Modules\Order\Application;

use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Queues customer-facing order updates. Queue failures are intentionally kept
 * outside order mutations so a mail/Telegram outage cannot roll back an order.
 */
final readonly class OrderNotificationService
{
    public function __construct(
        private Connection $db,
        private NotificationOutbox $outbox,
        private StorefrontUiTranslator $translator,
    ) {}

    public function enqueueCurrentStatus(string $orderPublicId): bool
    {
        try {
            $binary = Uuid::fromString($orderPublicId)->toBinary();
        } catch (\Throwable) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.nekorektnyi_identyfikator_zamovlennia'));
        }

        $order = $this->db->fetchAssociative(
            'SELECT o.order_number,o.status,o.payment_status,o.fulfillment_status,o.total_minor,o.currency,o.customer_name,o.customer_email,o.locale,f.tracking_number,f.provider_code
             FROM mc_sales_order o
             LEFT JOIN mc_fulfillment f ON f.order_id=o.id
             WHERE o.public_id=?
             ORDER BY f.id DESC
             LIMIT 1',
            [$binary],
        );
        if (!is_array($order)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.zamovlennia_ne_znaideno'));
        }

        $recipient = trim((string) ($order['customer_email'] ?? ''));
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $locale = trim((string) ($order['locale'] ?? '')) ?: 'en-US';
        $subject = $this->translator->translate('order_update_subject', $locale, ['number' => (string) $order['order_number']]);
        $message = new NotificationMessage(
            type: 'order.status_updated',
            subject: $subject,
            text: $this->plainText($order, $locale),
            context: [
                'locale' => $locale,
                'order_number' => (string) $order['order_number'],
                'customer_name' => (string) ($order['customer_name'] ?? ''),
                'status' => (string) $order['status'],
                'payment_status' => (string) $order['payment_status'],
                'fulfillment_status' => (string) $order['fulfillment_status'],
                'tracking_number' => (string) ($order['tracking_number'] ?? ''),
                'provider_code' => (string) ($order['provider_code'] ?? ''),
                'total_minor' => (int) $order['total_minor'],
                'currency' => (string) $order['currency'],
            ],
            emailTemplate: 'order_status',
        );
        $this->outbox->enqueue(NotificationChannel::Email, $message, $recipient);
        return true;
    }

    /** @param array<string,mixed> $order */
    private function plainText(array $order, string $locale): string
    {
        $parts = [
            $this->translator->translate('plain_order_status', $locale, ['status' => (string) $order['status']]),
            $this->translator->translate('plain_payment_status', $locale, ['status' => (string) $order['payment_status']]),
            $this->translator->translate('plain_delivery_status', $locale, ['status' => (string) $order['fulfillment_status']]),
        ];
        if (trim((string) ($order['tracking_number'] ?? '')) !== '') {
            $parts[] = $this->translator->translate('plain_tracking_number', $locale, ['number' => (string) $order['tracking_number']]);
        }
        return implode(' ', $parts);
    }

}
