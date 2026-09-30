<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Security;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Administrator password recovery. Tokens are stored only as SHA-256 hashes,
 * live 30 minutes, are single-use and throttled to one per 60 seconds. The
 * request endpoint never reveals whether an account exists.
 */
final readonly class AdminPasswordResetService
{
    public function __construct(
        private Connection $db,
        private UserPasswordHasherInterface $passwords,
        private NotificationOutbox $outbox,
        private string $publicBaseUrl,
    ) {
    }

    public function request(string $email): void
    {
        $normalized = mb_strtolower(trim($email), 'UTF-8');
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false || mb_strlen($normalized) > 320) {
            return;
        }
        $row = $this->db->fetchAssociative(
            "SELECT id,email FROM mc_admin_user WHERE email_normalized=? AND status='active' LIMIT 1",
            [$normalized],
        );
        if (!is_array($row)) {
            return;
        }
        $token = $this->issue((int) $row['id']);
        if ($token === null) {
            return;
        }
        $base = rtrim(trim($this->publicBaseUrl), '/');
        $url = $base . '/admin/reset/' . rawurlencode($token);
        $this->outbox->enqueue(
            NotificationChannel::Email,
            new NotificationMessage(
                type: 'admin_password_reset',
                subject: CanonicalUiText::get('admin.reset.mail_subject'),
                text: CanonicalUiText::get('admin.reset.mail_text'),
                context: [
                    'store_name' => 'Nexora Commerce',
                    'action_url' => $url,
                    'action_label' => CanonicalUiText::get('admin.reset.mail_action'),
                    'footer_text' => CanonicalUiText::get('admin.reset.mail_footer'),
                ],
                emailTemplate: 'generic',
            ),
            (string) $row['email'],
        );
    }

    public function isUsable(string $token): bool
    {
        $hash = $this->hash($token);

        return $hash !== null && (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM mc_admin_password_reset t JOIN mc_admin_user u ON u.id=t.admin_id WHERE t.token_hash=? AND t.consumed_at IS NULL AND t.expires_at>? AND u.status='active'",
            [$hash, $this->now()],
        ) === 1;
    }

    public function reset(string $token, string $password): bool
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new \DomainException(CanonicalUiText::get('admin.reset.too_short'));
        }
        $hash = $this->hash($token);
        if ($hash === null) {
            return false;
        }
        $now = $this->now();
        $this->db->beginTransaction();
        try {
            $row = $this->db->fetchAssociative(
                "SELECT t.id AS tid,u.id,u.public_id,u.email,u.display_name,u.roles,u.status FROM mc_admin_password_reset t JOIN mc_admin_user u ON u.id=t.admin_id WHERE t.token_hash=? AND t.consumed_at IS NULL AND t.expires_at>? AND u.status='active' FOR UPDATE",
                [$hash, $now],
            );
            if (!is_array($row)) {
                $this->db->rollBack();
                return false;
            }
            $user = new AdminUser(
                id: (int) $row['id'],
                publicId: Uuid::fromBinary((string) $row['public_id'])->toRfc4122(),
                email: (string) $row['email'],
                passwordHash: '',
                roles: [],
                displayName: (string) $row['display_name'],
                status: (string) $row['status'],
            );
            $this->db->update('mc_admin_user', ['password_hash' => $this->passwords->hashPassword($user, $password)], ['id' => (int) $row['id']]);
            $this->db->executeStatement('UPDATE mc_admin_password_reset SET consumed_at=? WHERE admin_id=? AND consumed_at IS NULL', [$now, (int) $row['id']]);
            $this->db->commit();

            return true;
        } catch (Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** CLI fallback for installations without working outgoing mail. */
    public function setPasswordForEmail(string $email, string $password): bool
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new \DomainException(CanonicalUiText::get('admin.reset.too_short'));
        }
        $row = $this->db->fetchAssociative(
            "SELECT id,public_id,email,display_name,status FROM mc_admin_user WHERE email_normalized=? LIMIT 1",
            [mb_strtolower(trim($email), 'UTF-8')],
        );
        if (!is_array($row)) {
            return false;
        }
        $user = new AdminUser(
            id: (int) $row['id'],
            publicId: Uuid::fromBinary((string) $row['public_id'])->toRfc4122(),
            email: (string) $row['email'],
            passwordHash: '',
            roles: [],
            displayName: (string) $row['display_name'],
            status: (string) $row['status'],
        );
        $this->db->update('mc_admin_user', ['password_hash' => $this->passwords->hashPassword($user, $password)], ['id' => (int) $row['id']]);
        $this->db->executeStatement('UPDATE mc_admin_password_reset SET consumed_at=? WHERE admin_id=? AND consumed_at IS NULL', [$this->now(), (int) $row['id']]);

        return true;
    }

    private function issue(int $adminId): ?string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $latest = $this->db->fetchOne(
            'SELECT created_at FROM mc_admin_password_reset WHERE admin_id=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1',
            [$adminId],
        );
        if (is_string($latest) && $latest !== '') {
            try {
                if (new DateTimeImmutable($latest, new DateTimeZone('UTC')) > $now->sub(new DateInterval('PT60S'))) {
                    return null;
                }
            } catch (Throwable) {
            }
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->insert('mc_admin_password_reset', [
            'admin_id' => $adminId,
            'token_hash' => hash('sha256', $token, true),
            'created_at' => $now->format('Y-m-d H:i:s.u'),
            'expires_at' => $now->add(new DateInterval('PT30M'))->format('Y-m-d H:i:s.u'),
        ]);

        return $token;
    }

    private function hash(string $token): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{32,80}$/D', $token) === 1 ? hash('sha256', $token, true) : null;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
