<?php

declare(strict_types=1);

namespace Commerce\Modules\Identity\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Customer\Application\CustomerStoreMembershipService;
use Commerce\Modules\Identity\Contract\ExternalIdentity;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Maps a verified external identity to a customer: known subject → that customer;
 * otherwise an existing account is linked only when BOTH sides verified the e-mail
 * (prevents pre-registration hijacking); otherwise a new account is created.
 */
final readonly class ExternalIdentityLoginService
{
    public function __construct(
        private Connection $db,
        private PublicIdFactory $ids,
        private CustomerStoreMembershipService $memberships,
    ) {}

    /** @return string customer e-mail (the security user identifier) */
    public function resolve(int $storeId, ExternalIdentity $identity, string $locale): string
    {
        if ($identity->email === null || !$identity->emailVerified || filter_var($identity->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('identity_email_unverified');
        }
        $email = mb_strtolower($identity->email);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $linked = $this->db->fetchAssociative(
            'SELECT c.id,c.email,c.status FROM mc_customer_identity i JOIN mc_customer c ON c.id=i.customer_id WHERE i.provider=? AND i.subject=? LIMIT 1',
            [$identity->provider, $identity->subject],
        );
        if (is_array($linked)) {
            $this->db->executeStatement('UPDATE mc_customer_identity SET last_login_at=? WHERE provider=? AND subject=?', [$now, $identity->provider, $identity->subject]);

            return $this->active($linked, $storeId);
        }

        $existing = $this->db->fetchAssociative('SELECT id,email,status,email_verified_at FROM mc_customer WHERE email_normalized=? LIMIT 1', [$email]);
        if (is_array($existing)) {
            if ($existing['email_verified_at'] === null) {
                throw new \DomainException('identity_local_unverified');
            }
            $this->link((int)$existing['id'], $identity, $now);
            return $this->active($existing, $storeId);
        }

        $publicId = $this->ids->generate();
        $displayName = $identity->displayName ?? mb_substr(strstr($email, '@', true) ?: $email, 0, 190);
        try {
            $customerId = $this->db->transactional(function (Connection $db) use ($publicId, $email, $displayName, $locale, $identity, $now): int {
                $db->insert('mc_customer', [
                    'public_id' => $publicId->toBinary(),
                    'email' => $email,
                    'email_normalized' => $email,
                    'phone_e164' => null,
                    'display_name' => $displayName,
                    'locale' => mb_substr($locale !== '' ? $locale : 'uk-UA', 0, 16),
                    // Unusable random hash: the account is reachable via the provider or the password-reset flow.
                    'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    'status' => 'active',
                    'email_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_seen_at' => null,
                ]);
                $id = (int)$db->lastInsertId();
                $this->link($id, $identity, $now, $db);
                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('identity_conflict');
        }
        $this->memberships->ensure($storeId, $customerId);
        return $email;
    }

    private function link(int $customerId, ExternalIdentity $identity, string $now, ?Connection $db = null): void
    {
        ($db ?? $this->db)->insert('mc_customer_identity', [
            'customer_id' => $customerId,
            'provider' => $identity->provider,
            'subject' => $identity->subject,
            'email_at_provider' => $identity->email,
            'created_at' => $now,
            'last_login_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $row */
    private function active(array $row, int $storeId): string
    {
        if ((string)$row['status'] !== 'active') {
            throw new \DomainException('identity_account_blocked');
        }
        $this->memberships->ensure($storeId, (int)$row['id']);
        return (string)$row['email'];
    }
}
