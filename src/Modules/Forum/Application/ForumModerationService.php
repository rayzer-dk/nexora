<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class ForumModerationService
{
    private const WARNING_LIMIT = 3;

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

    public function ban(int $storeId, int $customerId, string $reason, ?int $durationDays, string $actor = 'admin'): void
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
        $this->log($storeId, $actor, 'ban', $customerId, null, null, ($durationDays === null ? 'permanent: ' : $durationDays . 'd: ') . $reason);
    }

    public function revoke(int $storeId, int $banId, string $actor = 'admin'): void
    {
        $customerId = $this->connection->fetchOne('SELECT customer_id FROM mc_forum_ban WHERE id=? AND store_id=? AND revoked_at IS NULL', [$banId, $storeId]);
        $this->connection->executeStatement(
            "UPDATE mc_forum_ban SET revoked_at=? WHERE id=? AND store_id=? AND revoked_at IS NULL",
            [$this->now(), $banId, $storeId],
        );
        if ($customerId !== false) {
            $this->log($storeId, $actor, 'ban_revoked', (int) $customerId, null, null, null);
        }
    }

    /**
     * Gives a member a warning (1-3 points, valid for $validDays). When the active points reach the limit a seven-day ban follows.
     *
     * @return bool true when the warning led to an automatic ban
     */
    public function warn(int $storeId, int $customerId, string $reason, int $points = 1, int $validDays = 90, string $actor = 'admin'): bool
    {
        if (!$this->connection->fetchOne("SELECT 1 FROM mc_customer WHERE id=? AND status='active'", [$customerId])) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.customer_missing'));
        }
        $reason = mb_substr(trim(strip_tags($reason)), 0, 1000, 'UTF-8');
        if ($reason === '') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.ban_reason_required'));
        }
        $points = max(1, min(3, $points));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $validDays = max(1, min(3650, $validDays));
        $this->connection->insert('mc_forum_warning', [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'reason' => $reason,
            'points' => $points,
            'actor' => mb_substr($actor, 0, 190, 'UTF-8'),
            'expires_at' => $now->add(new DateInterval('P' . $validDays . 'D'))->format('Y-m-d H:i:s.u'),
            'revoked_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
        $this->log($storeId, $actor, 'warning', $customerId, null, null, $points . ': ' . $reason);
        $total = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(points),0) FROM mc_forum_warning WHERE store_id=? AND customer_id=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>?)',
            [$storeId, $customerId, $this->now()],
        );
        if ($total >= self::WARNING_LIMIT && $this->activeBan($storeId, $customerId) === null) {
            $this->ban($storeId, $customerId, \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.index.auto_ban_reason'), 7, 'system');

            return true;
        }

        return false;
    }

    public function revokeWarning(int $storeId, int $warningId, string $actor = 'admin'): void
    {
        $customerId = $this->connection->fetchOne('SELECT customer_id FROM mc_forum_warning WHERE id=? AND store_id=? AND revoked_at IS NULL', [$warningId, $storeId]);
        $this->connection->executeStatement('UPDATE mc_forum_warning SET revoked_at=? WHERE id=? AND store_id=? AND revoked_at IS NULL', [$this->now(), $warningId, $storeId]);
        if ($customerId !== false) {
            $this->log($storeId, $actor, 'warning_revoked', (int) $customerId, null, null, null);
        }
    }

    /** @return list<array<string,mixed>> */
    public function activeWarnings(int $storeId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));

        return $this->connection->fetchAllAssociative(
            "SELECT w.id,w.customer_id,w.reason,w.points,w.actor,w.expires_at,w.created_at,
                    COALESCE(NULLIF(fp.nickname,''),CONCAT('member-',LOWER(SUBSTRING(HEX(c.public_id),1,8)))) AS nickname
             FROM mc_forum_warning w
             JOIN mc_customer c ON c.id=w.customer_id
             LEFT JOIN mc_forum_profile fp ON fp.store_id=w.store_id AND fp.customer_id=w.customer_id
             WHERE w.store_id=? AND w.revoked_at IS NULL AND (w.expires_at IS NULL OR w.expires_at>?)
             ORDER BY w.created_at DESC,w.id DESC LIMIT {$limit}",
            [$storeId, $this->now()],
        );
    }

    /** Writes one line of the moderator action log; a failure here must never block the action itself. */
    public function log(int $storeId, string $actor, string $action, ?int $customerId = null, ?int $topicId = null, ?int $postId = null, ?string $note = null): void
    {
        try {
            $this->connection->insert('mc_forum_mod_log', [
                'store_id' => $storeId,
                'actor' => mb_substr($actor, 0, 190, 'UTF-8'),
                'action' => mb_substr($action, 0, 48, 'UTF-8'),
                'customer_id' => $customerId,
                'topic_id' => $topicId,
                'post_id' => $postId,
                'note' => $note === null ? null : mb_substr($note, 0, 500, 'UTF-8'),
                'created_at' => $this->now(),
            ]);
        } catch (\Throwable) {
            // The log table is created by a migration: before it runs, moderation keeps working without a log.
        }
    }

    /** @return list<array<string,mixed>> */
    public function recentLog(int $storeId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        try {
            return $this->connection->fetchAllAssociative(
                "SELECT l.id,l.actor,l.action,l.customer_id,l.topic_id,l.post_id,l.note,l.created_at,
                        (SELECT COALESCE(NULLIF(fp.nickname,''),CONCAT('#',l.customer_id)) FROM mc_forum_profile fp WHERE fp.store_id=l.store_id AND fp.customer_id=l.customer_id LIMIT 1) AS nickname
                 FROM mc_forum_mod_log l WHERE l.store_id=? ORDER BY l.id DESC LIMIT {$limit}",
                [$storeId],
            );
        } catch (\Throwable) {
            return [];
        }
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
