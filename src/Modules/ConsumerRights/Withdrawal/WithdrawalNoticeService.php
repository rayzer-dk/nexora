<?php

declare(strict_types=1);

namespace Commerce\Modules\ConsumerRights\Withdrawal;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Records a consumer's electronic withdrawal statement and acknowledges it on a durable medium (email).
 * The notice is always stored, even when the order cannot be matched, so the consumer's right is never
 * lost to a typo and the form cannot be used to probe which orders exist.
 */
final class WithdrawalNoticeService
{
    public function __construct(private readonly Connection $db, private readonly NotificationOutbox $notifications)
    {
    }

    /** @return array{reference:string,received_at:DateTimeImmutable} */
    public function record(int $storeId, string $locale, string $name, string $email, string $orderReference, string $scope, ?string $ip): array
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 190);
        $email = mb_substr(trim($email), 0, 320);
        $orderReference = mb_substr(trim(strip_tags($orderReference)), 0, 64);
        $scope = mb_substr(trim(strip_tags($scope)), 0, 1000);
        if ($name === '' || $orderReference === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('withdrawal_invalid');
        }
        $normalized = mb_strtolower($email);
        $orderId = $this->db->fetchOne(
            'SELECT id FROM mc_sales_order WHERE store_id = ? AND order_number = ? AND customer_email_normalized = ? LIMIT 1',
            [$storeId, $orderReference, $normalized],
        );
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $uuid = Uuid::v7();
        $this->db->insert('mc_withdrawal_notice', [
            'public_id' => $uuid->toBinary(),
            'store_id' => $storeId,
            'order_id' => $orderId !== false ? (int) $orderId : null,
            'order_reference' => $orderReference,
            'customer_name' => $name,
            'email' => $email,
            'email_normalized' => $normalized,
            'scope_note' => $scope !== '' ? $scope : null,
            'locale' => $locale,
            'status' => 'received',
            'ip_hash' => hash('sha256', (string) $ip),
            'received_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
        $reference = 'WD-' . strtoupper(substr(str_replace('-', '', $uuid->toRfc4122()), -10));
        $stamp = $now->format('Y-m-d H:i:s') . ' UTC';
        $text = CanonicalUiText::get('withdrawal_email_text', ['reference' => $reference, 'order' => $orderReference, 'received' => $stamp, 'name' => $name]);
        try {
            $this->notifications->enqueue(
                NotificationChannel::Email,
                new NotificationMessage('withdrawal.received', CanonicalUiText::get('withdrawal_email_subject'), $text, [], 'generic'),
                $email,
                null,
                'withdrawal:' . $uuid->toRfc4122(),
            );
            $this->db->update('mc_withdrawal_notice', ['acknowledged_at' => $now->format('Y-m-d H:i:s.u')], ['public_id' => $uuid->toBinary()]);
        } catch (\Throwable) {
            // The statement is stored and shown on the confirmation page; the outbox is retried by the worker.
        }

        return ['reference' => $reference, 'received_at' => $now];
    }
}
