<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Commerce\Core\I18n\StorefrontUiTranslator;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class CustomerVerificationCodeService
{
    public function __construct(
        private Connection $db,
        private NotificationOutbox $outbox,
        private StorefrontUiTranslator $translator,
        private string $appSecret,
        private bool $smsEnabled,
    ) {
    }

    public function requestCode(int $customerId, string $channel, string $storeName): bool
    {
        if (!in_array($channel, ['email', 'sms'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('verification.runtime.channel_unsupported'));
        }

        $row = $this->db->fetchAssociative(
            'SELECT id,email,phone_e164,email_verified_at,phone_verified_at,locale,status FROM mc_customer WHERE id=? LIMIT 1',
            [$customerId],
        );
        if (!is_array($row) || (string) $row['status'] !== 'active') {
            return false;
        }
        if ($channel === 'sms' && !$this->smsEnabled) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('verification.runtime.sms_disabled'));
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

        $locale = trim((string) ($row['locale'] ?? '')) ?: 'uk-UA';
        try {
            $subject = $this->translator->translate('verification_code_subject', $locale);
            $textTemplate = $this->translator->translate('verification_code_text', $locale, ['code' => '%code%']);
        } catch (\Throwable) {
            // Account verification is security-critical and must not become unavailable
            // because an optional extension translation catalog is malformed or unavailable.
            $subject = \Commerce\Core\I18n\CanonicalUiText::get('verification_code_subject');
            $textTemplate = \Commerce\Core\I18n\CanonicalUiText::get('verification_code_text', ['code' => '%code%']);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->db->transactional(function (Connection $db) use ($customerId, $channel, $storeName, $recipient, $locale, $subject, $textTemplate, $now): bool {
            // Lock the customer row so concurrent verification requests cannot create
            // multiple active codes or enqueue duplicate notifications.
            $locked = $db->fetchAssociative(
                'SELECT email_verified_at,phone_verified_at,status FROM mc_customer WHERE id=? LIMIT 1 FOR UPDATE',
                [$customerId],
            );
            if (!is_array($locked) || (string) $locked['status'] !== 'active') {
                return false;
            }
            if ($channel === 'email' && $locked['email_verified_at'] !== null) {
                return true;
            }
            if ($channel === 'sms' && $locked['phone_verified_at'] !== null) {
                return true;
            }

            $latest = $db->fetchAssociative(
                'SELECT id,created_at FROM mc_customer_verification_code WHERE customer_id=? AND channel=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1',
                [$customerId, $channel],
            );
            if (is_array($latest) && is_string($latest['created_at'] ?? null) && $latest['created_at'] !== '') {
                $created = new DateTimeImmutable((string) $latest['created_at'], new DateTimeZone('UTC'));
                if ($created > $now->modify('-60 seconds')) {
                    $hasQueuedNotification = (int) $db->fetchOne(
                        "SELECT COUNT(*) FROM mc_notification_outbox WHERE recipient=? AND notification_type='customer_verification_code' AND created_at>=? AND status IN ('pending','processing','sent')",
                        [$recipient, (string) $latest['created_at']],
                    ) > 0;
                    if ($hasQueuedNotification) {
                        return true;
                    }
                    // Repair a historical partial write: invalidate the orphaned code and issue
                    // a new code together with its notification in the same transaction.
                    $db->update('mc_customer_verification_code', ['consumed_at' => $now->format('Y-m-d H:i:s.u')], ['id' => (int) $latest['id']]);
                }
            }

            $code = (string) random_int(100000, 999999);
            $timestamp = $now->format('Y-m-d H:i:s.u');
            $db->executeStatement(
                'UPDATE mc_customer_verification_code SET consumed_at=? WHERE customer_id=? AND channel=? AND consumed_at IS NULL',
                [$timestamp, $customerId, $channel],
            );
            $db->insert('mc_customer_verification_code', [
                'customer_id' => $customerId,
                'channel' => $channel,
                'code_hash' => $this->hash($customerId, $channel, $code),
                'attempts' => 0,
                'expires_at' => $now->modify('+15 minutes')->format('Y-m-d H:i:s.u'),
                'consumed_at' => null,
                'created_at' => $timestamp,
            ]);
            $codeId = (string) $db->lastInsertId();

            $message = new NotificationMessage(
                type: 'customer_verification_code',
                subject: $subject,
                text: str_replace('%code%', $code, $textTemplate),
                context: ['store_name' => $storeName, 'verification_code' => $code, 'expires_minutes' => 15, 'locale' => $locale],
                emailTemplate: 'generic',
            );
            $this->outbox->enqueue(
                $channel === 'email' ? NotificationChannel::Email : NotificationChannel::Sms,
                $message,
                $recipient,
                null,
                'verify-code:' . $customerId . ':' . $channel . ':' . $codeId,
            );

            return true;
        });
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
