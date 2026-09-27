<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class ForumModerationService
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string,mixed>> */
    public function activeBans(int $storeId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        return $this->connection->fetchAllAssociative(
            "SELECT b.id,b.customer_id,b.reason,b.expires_at,b.created_at,
                    COALESCE(NULLIF(fp.nickname,''),CONCAT('member-',LOWER(SUBSTRING(HEX(c.public_id),1,8)))) AS nickname
             FROM mc_forum_ban b
             JOIN mc_customer c ON c.id=b.customer_id
             LEFT JOIN mc_forum_profile fp ON fp.store_id=b.store_id AND fp.customer_id=b.customer_id
             WHERE b.store_id=? AND b.revoked_at IS NULL AND (b.expires_at IS NULL OR b.expires_at>?)
             ORDER BY b.created_at DESC,b.id DESC
             LIMIT {$limit}",
            [$storeId, $this->now()],
        );
    }

    public function ban(int $storeId, int $customerId, string $reason, ?int $durationDays): void
    {
        $exists = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM mc_customer WHERE id=? AND status='active' LIMIT 1",
            [$customerId],
        );
        if (!$exists) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.customer_missing'));
        }

        $reason = mb_substr(trim(strip_tags($reason)), 0, 1000, 'UTF-8');
        if ($reason === '') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.ban_reason_required'));
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = null;
        if ($durationDays !== null) {
            $durationDays = max(1, min(3650, $durationDays));
            $expiresAt = $now->add(new DateInterval('P' . $durationDays . 'D'))->format('Y-m-d H:i:s.u');
        }

        $this->connection->executeStatement(
            "UPDATE mc_forum_ban SET revoked_at=? WHERE store_id=? AND customer_id=? AND revoked_at IS NULL",
            [$now->format('Y-m-d H:i:s.u'), $storeId, $customerId],
        );
        $this->connection->insert('mc_forum_ban', [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function revoke(int $storeId, int $banId): void
    {
        $this->connection->executeStatement(
            "UPDATE mc_forum_ban SET revoked_at=? WHERE id=? AND store_id=? AND revoked_at IS NULL",
            [$this->now(), $banId, $storeId],
        );
    }

    /** @return array<string,mixed>|null */
    public function activeBan(int $storeId, int $customerId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id,reason,expires_at,created_at FROM mc_forum_ban
             WHERE store_id=? AND customer_id=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>?)
             ORDER BY id DESC LIMIT 1",
            [$storeId, $customerId, $this->now()],
        );
        return is_array($row) ? $row : null;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
