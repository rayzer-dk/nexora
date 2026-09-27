<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class CustomerVerificationCodeService
{
    public function __construct(
        private Connection $db,
        private NotificationOutbox $outbox,
        private string $appSecret,
    ) {
    }

    public function requestCode(int $customerId, string $channel, string $storeName): bool
    {
        if (!in_array($channel, ['email', 'sms'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('verification.runtime.channel_unsupported'));
        }

        $row = $this->db->fetchAssociative(
            'SELECT id,email,phone_e164,email_verified_at,phone_verified_at,status FROM mc_customer WHERE id=? LIMIT 1',
            [$customerId],
        );
        if (!is_array($row) || (string) $row['status'] !== 'active') {
            return false;
        }
        if ($channel === 'email' && $row['email_verified_at'] !== null) {
            return true;
        }
        if ($channel === 'sms' && $row['phone_verified_at'] !== null) {
            return true;
        }

        $recipient = $channel === 'email' ? trim((string) ($row['email'] ?? '')) : trim((string) ($row['phone_e164'] ?? ''));
        if ($recipient === '') {
            throw new \DomainException($channel === 'sms' ? \Commerce\Core\I18n\CanonicalUiText::get('verification.runtime.phone_required') : \Commerce\Core\I18n\CanonicalUiText::get('verification.runtime.email_missing'));
        }

        $latest = $this->db->fetchOne(
            'SELECT created_at FROM mc_customer_verification_code WHERE customer_id=? AND channel=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1',
            [$customerId, $channel],
        );
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (is_string($latest) && $latest !== '') {
            $created = new DateTimeImmutable($latest, new DateTimeZone('UTC'));
            if ($created > $now->modify('-60 seconds')) {
                return true;
            }
        }

        $code = (string) random_int(100000, 999999);
        $this->db->executeStatement(
            'UPDATE mc_customer_verification_code SET consumed_at=? WHERE customer_id=? AND channel=? AND consumed_at IS NULL',
            [$now->format('Y-m-d H:i:s.u'), $customerId, $channel],
        );
        $this->db->insert('mc_customer_verification_code', [
            'customer_id' => $customerId,
            'channel' => $channel,
            'code_hash' => $this->hash($customerId, $channel, $code),
            'attempts' => 0,
            'expires_at' => $now->modify('+15 minutes')->format('Y-m-d H:i:s.u'),
            'consumed_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        $message = new NotificationMessage(
            type: 'customer_verification_code',
            subject: 'Verification code',
            text: 'Your verification code is ' . $code . '. It expires in 15 minutes.',
            context: ['store_name' => $storeName, 'verification_code' => $code, 'expires_minutes' => 15],
            emailTemplate: 'generic',
        );
        $this->outbox->enqueue(
            $channel === 'email' ? NotificationChannel::Email : NotificationChannel::Sms,
            $message,
            $recipient,
            null,
            'verify-code:' . $customerId . ':' . $channel . ':' . $this->db->lastInsertId(),
        );
        return true;
    }

    public function verify(int $customerId, string $channel, string $code): bool
    {
        $code = trim($code);
        if (!in_array($channel, ['email', 'sms'], true) || preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return false;
        }

        $now = $this->now();
        return $this->db->transactional(function (Connection $db) use ($customerId, $channel, $code, $now): bool {
            $row = $db->fetchAssociative(
                'SELECT id,code_hash,attempts FROM mc_customer_verification_code WHERE customer_id=? AND channel=? AND consumed_at IS NULL AND expires_at>? ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$customerId, $channel, $now],
            );
            if (!is_array($row) || (int) $row['attempts'] >= 5) {
                return false;
            }
            $valid = hash_equals((string) $row['code_hash'], $this->hash($customerId, $channel, $code));
            if (!$valid) {
                $db->executeStatement('UPDATE mc_customer_verification_code SET attempts=attempts+1 WHERE id=?', [(int) $row['id']]);
                return false;
            }

            $field = $channel === 'email' ? 'email_verified_at' : 'phone_verified_at';
            $db->executeStatement('UPDATE mc_customer SET ' . $field . '=?,updated_at=? WHERE id=?', [$now, $now, $customerId]);
            $db->executeStatement(
                'UPDATE mc_customer_verification_code SET consumed_at=? WHERE customer_id=? AND channel=? AND consumed_at IS NULL',
                [$now, $customerId, $channel],
            );
            return true;
        });
    }

    private function hash(int $customerId, string $channel, string $code): string
    {
        return hash_hmac('sha256', $customerId . '|' . $channel . '|' . $code, $this->appSecret, true);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
