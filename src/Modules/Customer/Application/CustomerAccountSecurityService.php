<?php

declare(strict_types=1);

namespace Commerce\Modules\Customer\Application;

use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Account verification and password recovery without storing raw tokens.
 * Service mail is queued; an unavailable SMTP server never blocks registration
 * or a storefront request because delivery happens asynchronously.
 */
final readonly class CustomerAccountSecurityService
{
    private const PURPOSE_VERIFY = 'verify_email';
    private const PURPOSE_RESET = 'password_reset';

    public function __construct(
        private Connection $db,
        private UserPasswordHasherInterface $passwords,
        private NotificationOutbox $outbox,
        private string $publicBaseUrl,
    ) {
    }

    public function queueVerificationForCustomer(int $customerId, string $storeName): bool
    {
        $row = $this->db->fetchAssociative(
            "SELECT id,email,email_verified_at,status FROM mc_customer WHERE id=? LIMIT 1",
            [$customerId],
        );
        if (!is_array($row) || (string) $row['status'] !== 'active' || !is_string($row['email']) || trim($row['email']) === '') {
            return false;
        }
        if ($row['email_verified_at'] !== null) {
            return true;
        }
        $token = $this->issue((int) $row['id'], self::PURPOSE_VERIFY, new DateInterval('P1D'));
        if ($token === null) {
            return true;
        }

        $url = $this->absoluteUrl('/account/verify/' . rawurlencode($token));
        $this->outbox->enqueue(
            NotificationChannel::Email,
            new NotificationMessage(
                type: 'customer_email_verification',
                subject: \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customeraccountsecurityservice.pidtverdit_email'),
                text: \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customeraccountsecurityservice.pidtverdit_email_adresu_dlia_oblikovoho_zapysu'),
                context: [
                    'store_name' => $storeName,
                    'action_url' => $url,
                    'action_label' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customeraccountsecurityservice.pidtverdyty_email'),
                    'footer_text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customeraccountsecurityservice.posylannia_diie_24_hodyny_yakshcho_vy_ne_stvoriuvaly'),
                ],
                emailTemplate: 'generic',
            ),
            (string) $row['email'],
        );

        return true;
    }

    /**
     * Always returns true to the caller. This deliberately avoids an account
     * enumeration oracle even when no matching account exists.
     */
    public function requestPasswordReset(string $email, string $storeName): bool
    {
        $normalized = mb_strtolower(trim($email), 'UTF-8');
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false || mb_strlen($normalized) > 320) {
            return true;
        }

        $row = $this->db->fetchAssociative(
            "SELECT id,email,status FROM mc_customer WHERE email_normalized=? LIMIT 1",
            [$normalized],
        );
        if (!is_array($row) || (string) $row['status'] !== 'active' || !is_string($row['email']) || $row['email'] === '') {
            return true;
        }

        $token = $this->issue((int) $row['id'], self::PURPOSE_RESET, new DateInterval('PT30M'));
        if ($token === null) {
            return true;
        }
        $url = $this->absoluteUrl('/account/password/reset/' . rawurlencode($token));
        $this->outbox->enqueue(
            NotificationChannel::Email,
            new NotificationMessage(
                type: 'customer_password_reset',
                subject: \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.http.customersecuritycontroller.vidnovlennia_parolia'),
                text: \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customeraccountsecurityservice.dlia_vstanovlennia_novoho_parolia_skorystaitesia_zak'),
                context: [
                    'store_name' => $storeName,
                    'action_url' => $url,
                    'action_label' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customeraccountsecurityservice.vstanovyty_novyi_parol'),
                    'footer_text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customeraccountsecurityservice.posylannia_diie_30_khvylyn_i_mozhe_buty_vykorystane_'),
                ],
                emailTemplate: 'generic',
            ),
            (string) $row['email'],
        );

        return true;
    }

    public function verifyEmail(string $token): bool
    {
        return $this->consume($token, self::PURPOSE_VERIFY, function (array $row, string $now): void {
            $customerId = (int) $row['customer_id'];
            $this->db->update('mc_customer', [
                'email_verified_at' => $now,
                'updated_at' => $now,
            ], ['id' => $customerId]);

            // Verification is an account-level state. Once one valid link succeeds,
            // every other outstanding verification link for the same customer is
            // invalidated in the same transaction. Old links can never become a
            // second long-lived credential.
            $this->db->executeStatement(
                'UPDATE mc_customer_account_token SET consumed_at=? WHERE customer_id=? AND purpose=? AND consumed_at IS NULL',
                [$now, $customerId, self::PURPOSE_VERIFY],
            );
        });
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        if (strlen($newPassword) < 12 || strlen($newPassword) > 4096) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customeraccountsecurityservice.novyi_parol_maie_mistyty_shchonaimenshe_12_symvoliv'));
        }

        return $this->consume($token, self::PURPOSE_RESET, function (array $row, string $now) use ($newPassword): void {
            $customer = $this->db->fetchAssociative(
                'SELECT id,public_id,email,password_hash,display_name,status FROM mc_customer WHERE id=? LIMIT 1',
                [(int) $row['customer_id']],
            );
            if (!is_array($customer) || (string) $customer['status'] !== 'active') {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ccbb15f160d2'));
            }
            $user = new CustomerUser(
                (int) $customer['id'],
                Uuid::fromBinary((string) $customer['public_id'])->toRfc4122(),
                (string) $customer['email'],
                (string) ($customer['password_hash'] ?? ''),
                (string) ($customer['display_name'] ?? ''),
                (string) $customer['status'],
            );
            $this->db->update('mc_customer', [
                'password_hash' => $this->passwords->hashPassword($user, $newPassword),
                'updated_at' => $now,
            ], ['id' => (int) $customer['id']]);

            // Any other outstanding reset link becomes invalid immediately.
            $this->db->executeStatement(
                'UPDATE mc_customer_account_token SET consumed_at=? WHERE customer_id=? AND purpose=? AND consumed_at IS NULL',
                [$now, (int) $customer['id'], self::PURPOSE_RESET],
            );
        });
    }

    public function tokenIsUsable(string $token, string $purpose): bool
    {
        if (!in_array($purpose, [self::PURPOSE_VERIFY, self::PURPOSE_RESET], true)) {
            return false;
        }
        $hash = $this->hashToken($token);
        if ($hash === null) {
            return false;
        }
        $now = $this->now();

        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM mc_customer_account_token t JOIN mc_customer c ON c.id=t.customer_id WHERE t.token_hash=? AND t.purpose=? AND t.consumed_at IS NULL AND t.expires_at>? AND c.status=\'active\'',
            [$hash, $purpose, $now],
        ) === 1;
    }

    public static function resetPurpose(): string
    {
        return self::PURPOSE_RESET;
    }

    /** @param callable(array<string,mixed>,string):void $callback */
    private function consume(string $token, string $purpose, callable $callback): bool
    {
        $hash = $this->hashToken($token);
        if ($hash === null) {
            return false;
        }
        $now = $this->now();
        $this->db->beginTransaction();
        try {
            $row = $this->db->fetchAssociative(
                'SELECT id,customer_id FROM mc_customer_account_token WHERE token_hash=? AND purpose=? AND consumed_at IS NULL AND expires_at>? FOR UPDATE',
                [$hash, $purpose, $now],
            );
            if (!is_array($row)) {
                $this->db->rollBack();
                return false;
            }

            $callback($row, $now);
            $this->db->update('mc_customer_account_token', ['consumed_at' => $now], ['id' => (int) $row['id']]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function issue(int $customerId, string $purpose, DateInterval $ttl): ?string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $latest = $this->db->fetchOne(
            'SELECT created_at FROM mc_customer_account_token WHERE customer_id=? AND purpose=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1',
            [$customerId, $purpose],
        );
        if (is_string($latest) && $latest !== '') {
            try {
                $created = new DateTimeImmutable($latest, new DateTimeZone('UTC'));
                if ($created > $now->sub(new DateInterval('PT60S'))) {
                    return null;
                }
            } catch (Throwable) {
                // Invalid historical timestamp must not block a new safe token.
            }
        }

        $raw = random_bytes(32);
        $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $this->db->insert('mc_customer_account_token', [
            'customer_id' => $customerId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token, true),
            'expires_at' => $now->add($ttl)->format('Y-m-d H:i:s.u'),
            'consumed_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        return $token;
    }

    private function hashToken(string $token): ?string
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 32 || strlen($token) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            return null;
        }
        return hash('sha256', $token, true);
    }

    private function absoluteUrl(string $path): string
    {
        $base = rtrim(trim($this->publicBaseUrl), '/');
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $base)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b13705d90fb4'));
        }
        return $base . '/' . ltrim($path, '/');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
