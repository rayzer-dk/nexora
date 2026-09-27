<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class ForumCommunityService
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array<string,mixed>> */
    public function search(int $storeId, string $query, int $limit = 50): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', strip_tags($query)) ?? '');
        if (mb_strlen($query, 'UTF-8') < 2) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        return $this->connection->fetchAllAssociative(
            "SELECT DISTINCT t.id,t.title,t.slug,b.slug AS board_slug,b.name AS board_name,
                    COALESCE(NULLIF(c.display_name,''),t.author_name) AS author_name,
                    t.last_post_at,t.published_at,t.created_at
             FROM mc_forum_topic t
             JOIN mc_forum_board b ON b.id=t.board_id
             LEFT JOIN mc_customer c ON c.id=t.customer_id
             LEFT JOIN mc_forum_post p ON p.topic_id=t.id AND p.status='published'
             WHERE b.store_id=? AND b.status='active' AND t.status='published'
               AND (t.title LIKE ? ESCAPE '\\' OR p.body_text LIKE ? ESCAPE '\\')
             ORDER BY COALESCE(t.last_post_at,t.published_at,t.created_at) DESC,t.id DESC
             LIMIT {$limit}",
            [$storeId, $needle, $needle],
        );
    }

    public function recordView(int $storeId, int $topicId): void
    {
        $this->connection->executeStatement(
            "UPDATE mc_forum_topic t
             JOIN mc_forum_board b ON b.id=t.board_id
             SET t.views_count=t.views_count+1
             WHERE t.id=? AND b.store_id=? AND t.status='published'",
            [$topicId, $storeId],
        );
    }

    public function isSubscribed(int $storeId, int $topicId, int $customerId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM mc_forum_subscription WHERE store_id=? AND topic_id=? AND customer_id=? LIMIT 1',
            [$storeId, $topicId, $customerId],
        );
    }

    public function setSubscription(int $storeId, int $topicId, int $customerId, bool $enabled): void
    {
        $this->assertTopicInStore($storeId, $topicId);
        if ($enabled) {
            $this->connection->executeStatement(
                'INSERT IGNORE INTO mc_forum_subscription(store_id,topic_id,customer_id,created_at) VALUES (?,?,?,?)',
                [$storeId, $topicId, $customerId, $this->now()],
            );
            return;
        }
        $this->connection->delete('mc_forum_subscription', [
            'store_id' => $storeId,
            'topic_id' => $topicId,
            'customer_id' => $customerId,
        ]);
    }

    public function toggleLike(int $storeId, int $postId, int $customerId): bool
    {
        $this->assertPostInStore($storeId, $postId);
        $exists = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM mc_forum_reaction WHERE post_id=? AND customer_id=? AND reaction='like' LIMIT 1",
            [$postId, $customerId],
        );
        if ($exists) {
            $this->connection->delete('mc_forum_reaction', [
                'post_id' => $postId,
                'customer_id' => $customerId,
                'reaction' => 'like',
            ]);
            return false;
        }
        $this->connection->insert('mc_forum_reaction', [
            'post_id' => $postId,
            'customer_id' => $customerId,
            'reaction' => 'like',
            'created_at' => $this->now(),
        ]);
        return true;
    }

    public function reportPost(int $storeId, int $postId, int $customerId, string $reason, string $details): void
    {
        $this->assertPostInStore($storeId, $postId);
        $allowed = ['spam', 'abuse', 'offtopic', 'misinformation', 'copyright', 'other'];
        if (!in_array($reason, $allowed, true)) {
            $reason = 'other';
        }
        $details = mb_substr(trim(strip_tags($details)), 0, 1000, 'UTF-8');
        $this->connection->executeStatement(
            "INSERT INTO mc_forum_report(store_id,post_id,customer_id,reason,details,status,created_at)
             VALUES (?,?,?,?,?,'open',?)
             ON DUPLICATE KEY UPDATE reason=VALUES(reason),details=VALUES(details),status='open',created_at=VALUES(created_at),resolved_at=NULL",
            [$storeId, $postId, $customerId, $reason, $details !== '' ? $details : null, $this->now()],
        );
    }

    public function editOwnPost(int $storeId, int $postId, int $customerId, string $body): void
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", strip_tags($body)));
        if (mb_strlen($body, 'UTF-8') < 3) {
            throw new \DomainException('Message is too short.');
        }
        $body = mb_substr($body, 0, 20000, 'UTF-8');
        $row = $this->connection->fetchAssociative(
            "SELECT p.id,p.body_text,p.created_at
             FROM mc_forum_post p
             JOIN mc_forum_topic t ON t.id=p.topic_id
             JOIN mc_forum_board b ON b.id=t.board_id
             WHERE p.id=? AND p.customer_id=? AND b.store_id=? AND p.status='published' LIMIT 1",
            [$postId, $customerId, $storeId],
        );
        if (!is_array($row)) {
            throw new \DomainException('Forum post was not found.');
        }
        $created = new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (($now->getTimestamp() - $created->getTimestamp()) > 1800) {
            throw new \DomainException('The 30 minute editing window has expired.');
        }

        $this->connection->transactional(function (Connection $db) use ($row, $postId, $customerId, $body, $now): void {
            $stamp = $now->format('Y-m-d H:i:s.u');
            $db->insert('mc_forum_post_revision', [
                'post_id' => $postId,
                'editor_customer_id' => $customerId,
                'body_text' => (string) $row['body_text'],
                'created_at' => $stamp,
            ]);
            $db->executeStatement(
                'UPDATE mc_forum_post SET body_text=?,edited_at=?,edit_count=edit_count+1,updated_at=? WHERE id=?',
                [$body, $stamp, $stamp, $postId],
            );
        });
    }

    /** @return array<string,int> */
    public function memberStats(int $storeId, int $customerId): array
    {
        return [
            'topics' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND t.customer_id=? AND t.status='published'",
                [$storeId, $customerId],
            ),
            'posts' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND p.customer_id=? AND p.status='published'",
                [$storeId, $customerId],
            ),
            'likes' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM mc_forum_reaction r JOIN mc_forum_post p ON p.id=r.post_id JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND p.customer_id=? AND r.reaction='like'",
                [$storeId, $customerId],
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function openReports(int $storeId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        return $this->connection->fetchAllAssociative(
            "SELECT r.id,r.post_id,r.reason,r.details,r.created_at,
                    COALESCE(NULLIF(rc.display_name,''),rc.email) AS reporter_name,
                    p.body_text,t.id AS topic_id,t.title AS topic_title
             FROM mc_forum_report r
             JOIN mc_forum_post p ON p.id=r.post_id
             JOIN mc_forum_topic t ON t.id=p.topic_id
             JOIN mc_customer rc ON rc.id=r.customer_id
             WHERE r.store_id=? AND r.status='open'
             ORDER BY r.created_at ASC,r.id ASC
             LIMIT {$limit}",
            [$storeId],
        );
    }

    public function resolveReport(int $storeId, int $reportId): void
    {
        $this->connection->executeStatement(
            "UPDATE mc_forum_report SET status='resolved',resolved_at=? WHERE id=? AND store_id=? AND status='open'",
            [$this->now(), $reportId, $storeId],
        );
    }

    private function assertTopicInStore(int $storeId, int $topicId): void
    {
        $ok = $this->connection->fetchOne(
            "SELECT 1 FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id
             WHERE t.id=? AND b.store_id=? AND t.status='published' LIMIT 1",
            [$topicId, $storeId],
        );
        if (!$ok) {
            throw new \DomainException('Forum topic was not found.');
        }
    }

    private function assertPostInStore(int $storeId, int $postId): void
    {
        $ok = $this->connection->fetchOne(
            "SELECT 1 FROM mc_forum_post p
             JOIN mc_forum_topic t ON t.id=p.topic_id
             JOIN mc_forum_board b ON b.id=t.board_id
             WHERE p.id=? AND b.store_id=? AND p.status='published' AND t.status='published' LIMIT 1",
            [$postId, $storeId],
        );
        if (!$ok) {
            throw new \DomainException('Forum post was not found.');
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
