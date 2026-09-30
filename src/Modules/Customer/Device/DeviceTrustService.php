<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Device;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Trusted-device confirmation for customer sign-in: an unrecognised browser has to enter a code
 * e-mailed to the account owner. The device token lives in an HttpOnly cookie and is stored hashed.
 */
final readonly class DeviceTrustService
{
    public const COOKIE = 'nx_dev';
    public const TRUST_DAYS = 180;
    private const CHANNEL = 'device';

    public function __construct(
        private Connection $db,
        private NotificationOutbox $outbox,
        private StorefrontUiTranslator $translator,
        private string $appSecret,
    ) {
    }

    public function isTrusted(int $customerId, ?string $token): bool
    {
        if ($token === null || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return false;
        }
        $hash = hash('sha256', $token, true);
        $id = $this->db->fetchOne('SELECT id FROM mc_customer_device WHERE customer_id=? AND token_hash=? AND expires_at>UTC_TIMESTAMP(6) LIMIT 1', [$customerId, $hash]);
        if ($id === false) {
            return false;
        }
        $this->db->executeStatement('UPDATE mc_customer_device SET last_used_at=UTC_TIMESTAMP(6) WHERE id=?', [(int) $id]);

        return true;
    }

    /** Sends a fresh 6-digit code unless one was sent in the last minute. */
    public function challenge(int $customerId, string $storeName): void
    {
        $row = $this->db->fetchAssociative("SELECT email,locale FROM mc_customer WHERE id=? AND status='active' LIMIT 1", [$customerId]);
        if (!is_array($row) || trim((string) $row['email']) === '') {
            return;
        }
        $locale = trim((string) ($row['locale'] ?? '')) ?: 'uk-UA';
        try {
            $subject = $this->translator->translate('device_code_subject', $locale);
            $template = $this->translator->translate('device_code_text', $locale, ['code' => '%code%']);
        } catch (\Throwable) {
            $subject = CanonicalUiText::get('device_code_subject');
            $template = CanonicalUiText::get('device_code_text', ['code' => '%code%']);
        }
        $now = $this->now();
        $this->db->transactional(function (Connection $db) use ($customerId, $row, $locale, $subject, $template, $storeName, $now): void {
            $latest = $db->fetchOne('SELECT created_at FROM mc_customer_verification_code WHERE customer_id=? AND channel=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE', [$customerId, self::CHANNEL]);
            if (is_string($latest) && new DateTimeImmutable($latest, new DateTimeZone('UTC')) > (new DateTimeImmutable($now, new DateTimeZone('UTC')))->modify('-60 seconds')) {
                return;
            }
            $db->executeStatement('UPDATE mc_customer_verification_code SET consumed_at=? WHERE customer_id=? AND channel=? AND consumed_at IS NULL', [$now, $customerId, self::CHANNEL]);
            $code = (string) random_int(100000, 999999);
            $db->insert('mc_customer_verification_code', [
                'customer_id' => $customerId,
                'channel' => self::CHANNEL,
                'code_hash' => $this->hash($customerId, $code),
                'attempts' => 0,
                'expires_at' => (new DateTimeImmutable($now, new DateTimeZone('UTC')))->modify('+15 minutes')->format('Y-m-d H:i:s.u'),
                'consumed_at' => null,
                'created_at' => $now,
            ]);
            $codeId = (string) $db->lastInsertId();
            $this->outbox->enqueue(
                NotificationChannel::Email,
                new NotificationMessage(
                    type: 'customer_device_code',
                    subject: $subject,
                    text: str_replace('%code%', $code, $template),
                    context: ['store_name' => $storeName, 'verification_code' => $code, 'expires_minutes' => 15, 'locale' => $locale],
                    emailTemplate: 'generic',
                ),
                (string) $row['email'],
                null,
                'device-code:' . $customerId . ':' . $codeId,
            );
        });
    }

    public function verify(int $customerId, string $code): bool
    {
        $code = trim($code);
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return false;
        }
        $now = $this->now();

        return $this->db->transactional(function (Connection $db) use ($customerId, $code, $now): bool {
            $row = $db->fetchAssociative('SELECT id,code_hash,attempts FROM mc_customer_verification_code WHERE customer_id=? AND channel=? AND consumed_at IS NULL AND expires_at>? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$customerId, self::CHANNEL, $now]);
            if (!is_array($row) || (int) $row['attempts'] >= 5) {
                return false;
            }
            if (!hash_equals((string) $row['code_hash'], $this->hash($customerId, $code))) {
                $db->executeStatement('UPDATE mc_customer_verification_code SET attempts=attempts+1 WHERE id=?', [(int) $row['id']]);

                return false;
            }
            $db->executeStatement('UPDATE mc_customer_verification_code SET consumed_at=? WHERE customer_id=? AND channel=? AND consumed_at IS NULL', [$now, $customerId, self::CHANNEL]);

            return true;
        });
    }

    /** Registers the current browser and returns the raw cookie token. */
    public function trust(int $customerId, string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->db->insert('mc_customer_device', [
            'customer_id' => $customerId,
            'token_hash' => hash('sha256', $token, true),
            'label' => self::label($userAgent),
            'created_at' => $now->format('Y-m-d H:i:s.u'),
            'last_used_at' => $now->format('Y-m-d H:i:s.u'),
            'expires_at' => $now->modify('+' . self::TRUST_DAYS . ' days')->format('Y-m-d H:i:s.u'),
        ]);

        return $token;
    }

    public function revokeAll(int $customerId): void
    {
        $this->db->delete('mc_customer_device', ['customer_id' => $customerId]);
    }

    public static function label(string $userAgent): string
    {
        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Device',
        };
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        return $browser . ' / ' . $os;
    }

    private function hash(int $customerId, string $code): string
    {
        return hash_hmac('sha256', $customerId . '|' . self::CHANNEL . '|' . $code, $this->appSecret, true);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
