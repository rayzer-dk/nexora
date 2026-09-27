<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class ForumDirectMessageService
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private ForumAccessPolicy $accessPolicy,
        private ForumProfileService $profiles,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function threads(int $storeId, int $customerId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        return $this->connection->fetchAllAssociative(
            "SELECT t.id,t.public_id,t.last_message_at,t.created_at,
                    CASE WHEN t.customer_low_id=? THEN t.customer_high_id ELSE t.customer_low_id END AS other_customer_id,
                    p.nickname AS other_nickname,
                    (SELECT COUNT(*) FROM mc_forum_dm_message m WHERE m.thread_id=t.id AND m.sender_customer_id<>? AND m.read_at IS NULL) AS unread_count
             FROM mc_forum_dm_thread t
             JOIN mc_forum_profile p ON p.store_id=t.store_id
              AND p.customer_id=CASE WHEN t.customer_low_id=? THEN t.customer_high_id ELSE t.customer_low_id END
             WHERE t.store_id=? AND (?=t.customer_low_id OR ?=t.customer_high_id)
             ORDER BY COALESCE(t.last_message_at,t.created_at) DESC,t.id DESC
             LIMIT {$limit}",
            [$customerId, $customerId, $customerId, $storeId, $customerId, $customerId],
        );
    }

    /** @return array{thread:array<string,mixed>,messages:list<array<string,mixed>>}|null */
    public function thread(int $storeId, int $threadId, int $customerId): ?array
    {
        $thread = $this->connection->fetchAssociative(
            "SELECT t.id,t.store_id,t.customer_low_id,t.customer_high_id,
                    CASE WHEN t.customer_low_id=? THEN t.customer_high_id ELSE t.customer_low_id END AS other_customer_id
             FROM mc_forum_dm_thread t
             WHERE t.id=? AND t.store_id=? AND (?=t.customer_low_id OR ?=t.customer_high_id) LIMIT 1",
            [$customerId, $threadId, $storeId, $customerId, $customerId],
        );
        if (!is_array($thread)) {
            return null;
        }
        $otherId = (int) $thread['other_customer_id'];
        $other = $this->profiles->publicProfile($storeId, $otherId);
        $thread['other_nickname'] = (string) ($other['nickname'] ?? '');

        $messages = $this->connection->fetchAllAssociative(
            "SELECT m.id,m.sender_customer_id,m.body_text,m.created_at,m.read_at,p.nickname AS sender_nickname
             FROM mc_forum_dm_message m
             JOIN mc_forum_profile p ON p.store_id=? AND p.customer_id=m.sender_customer_id
             WHERE m.thread_id=? AND m.status='sent'
             ORDER BY m.id ASC LIMIT 200",
            [$storeId, $threadId],
        );
        $this->connection->executeStatement(
            'UPDATE mc_forum_dm_message SET read_at=? WHERE thread_id=? AND sender_customer_id<>? AND read_at IS NULL',
            [$this->now(), $threadId, $customerId],
        );
        return ['thread' => $thread, 'messages' => $messages];
    }

    public function send(int $storeId, int $senderId, int $recipientId, string $body): int
    {
        $this->accessPolicy->assertCanParticipate($senderId);
        if ($senderId === $recipientId) {
            throw new \DomainException('You cannot send a private message to yourself.');
        }
        $recipientProfile = $this->profiles->publicProfile($storeId, $recipientId);
        if (!is_array($recipientProfile) || (int) ($recipientProfile['allow_private_messages'] ?? 0) !== 1) {
            throw new \DomainException('This member does not accept private messages.');
        }
        if ($this->blocked($storeId, $senderId, $recipientId)) {
            throw new \DomainException('Private messaging is not available between these members.');
        }

        $body = trim(str_replace(["\r\n", "\r"], "\n", strip_tags($body)));
        if (mb_strlen($body, 'UTF-8') < 2 || mb_strlen($body, 'UTF-8') > 5000) {
            throw new \DomainException('Private message must contain 2–5000 characters.');
        }
        preg_match_all('#https?://#iu', $body, $matches);
        if (count($matches[0]) > 2) {
            throw new \DomainException('Too many links in a private message.');
        }
        $recentCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM mc_forum_dm_message WHERE sender_customer_id=? AND created_at>=?',
            [$senderId, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 hour')->format('Y-m-d H:i:s.u')],
        );
        if ($recentCount >= 20) {
            throw new \DomainException('Private message limit reached. Try again later.');
        }

        $duplicate = $this->connection->fetchOne(
            "SELECT 1 FROM mc_forum_dm_message WHERE sender_customer_id=? AND body_text=? AND created_at>=? LIMIT 1",
            [$senderId, $body, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s.u')],
        );
        if ($duplicate) {
            throw new \DomainException('Duplicate private message blocked.');
        }

        [$low, $high] = $senderId < $recipientId ? [$senderId, $recipientId] : [$recipientId, $senderId];
        $now = $this->now();
        $threadId = $this->connection->fetchOne(
            'SELECT id FROM mc_forum_dm_thread WHERE store_id=? AND customer_low_id=? AND customer_high_id=? LIMIT 1',
            [$storeId, $low, $high],
        );
        if ($threadId === false) {
            $this->connection->insert('mc_forum_dm_thread', [
                'public_id' => $this->publicIds->binary(),
                'store_id' => $storeId,
                'customer_low_id' => $low,
                'customer_high_id' => $high,
                'last_message_at' => $now,
                'created_at' => $now,
            ]);
            $threadId = (int) $this->connection->lastInsertId();
        }

        $this->connection->insert('mc_forum_dm_message', [
            'thread_id' => (int) $threadId,
            'sender_customer_id' => $senderId,
            'body_text' => $body,
            'status' => 'sent',
            'created_at' => $now,
            'read_at' => null,
        ]);
        $messageId = (int) $this->connection->lastInsertId();
        $this->connection->update('mc_forum_dm_thread', ['last_message_at' => $now], ['id' => (int) $threadId]);
        return $messageId;
    }

    public function block(int $storeId, int $blockerId, int $blockedId): void
    {
        if ($blockerId === $blockedId) {
            return;
        }
        $this->connection->executeStatement(
            'INSERT IGNORE INTO mc_forum_block(store_id,blocker_customer_id,blocked_customer_id,created_at) VALUES (?,?,?,?)',
            [$storeId, $blockerId, $blockedId, $this->now()],
        );
    }

    public function unblock(int $storeId, int $blockerId, int $blockedId): void
    {
        $this->connection->delete('mc_forum_block', [
            'store_id' => $storeId,
            'blocker_customer_id' => $blockerId,
            'blocked_customer_id' => $blockedId,
        ]);
    }

    public function report(int $storeId, int $messageId, int $reporterId, string $reason, string $details): void
    {
        $message = $this->connection->fetchAssociative(
            "SELECT m.id,t.customer_low_id,t.customer_high_id FROM mc_forum_dm_message m
             JOIN mc_forum_dm_thread t ON t.id=m.thread_id
             WHERE m.id=? AND t.store_id=? AND (?=t.customer_low_id OR ?=t.customer_high_id) LIMIT 1",
            [$messageId, $storeId, $reporterId, $reporterId],
        );
        if (!is_array($message)) {
            throw new \DomainException('Private message was not found.');
        }
        $allowed = ['spam','abuse','harassment','fraud','other'];
        if (!in_array($reason, $allowed, true)) {
            $reason = 'other';
        }
        $details = mb_substr(trim(strip_tags($details)), 0, 1000, 'UTF-8');
        $this->connection->executeStatement(
            "INSERT INTO mc_forum_dm_report(store_id,message_id,reporter_customer_id,reason,details,status,created_at)
             VALUES (?,?,?,?,?,'open',?)
             ON DUPLICATE KEY UPDATE reason=VALUES(reason),details=VALUES(details),status='open',created_at=VALUES(created_at),resolved_at=NULL",
            [$storeId, $messageId, $reporterId, $reason, $details !== '' ? $details : null, $this->now()],
        );
    }

    private function blocked(int $storeId, int $a, int $b): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM mc_forum_block WHERE store_id=? AND ((blocker_customer_id=? AND blocked_customer_id=?) OR (blocker_customer_id=? AND blocked_customer_id=?)) LIMIT 1',
            [$storeId, $a, $b, $b, $a],
        );
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
