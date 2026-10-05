<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/** Up and down votes, the "solution" mark, member ranks and reputation, who is online and the forum statistics. */
final readonly class ForumEngagementService
{
    /** Points a member needs for each rank: posts + 3 × reputation. The key is the translation suffix. */
    private const RANKS = [5 => 'veteran', 4 => 'expert', 3 => 'active', 2 => 'member'];
    private const RANK_POINTS = [5 => 400, 4 => 120, 3 => 30, 2 => 5];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Votes a post up or down; voting the same way again takes the vote back, the other way switches it.
     *
     * @return array{score:int,mine:int}
     */
    public function vote(int $storeId, int $postId, int $customerId, string $direction): array
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new \DomainException('direction');
        }
        $post = $this->connection->fetchAssociative(
            "SELECT p.customer_id FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id
             WHERE p.id=? AND b.store_id=? AND p.status='published' AND t.status='published' LIMIT 1",
            [$postId, $storeId],
        );
        if (!is_array($post)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.povidomlennia_ne_znaideno'));
        }
        if ((int) ($post['customer_id'] ?? 0) === $customerId) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.vote_own'));
        }
        $current = $this->myVote($postId, $customerId);
        $wanted = $direction === 'up' ? 1 : -1;
        $this->connection->executeStatement("DELETE FROM mc_forum_reaction WHERE post_id=? AND customer_id=? AND reaction IN ('like','up','down')", [$postId, $customerId]);
        if ($current !== $wanted) {
            $this->connection->insert('mc_forum_reaction', ['post_id' => $postId, 'customer_id' => $customerId, 'reaction' => $direction, 'created_at' => $this->now()]);
        }

        return ['score' => $this->score($postId), 'mine' => $current === $wanted ? 0 : $wanted];
    }

    /** Marks a reply as the solution of its topic (or takes the mark away). Only the topic author may do it. */
    public function setSolution(int $storeId, int $topicId, int $postId, int $customerId): bool
    {
        $topic = $this->connection->fetchAssociative(
            "SELECT t.id,t.customer_id,t.solved_post_id FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE t.id=? AND b.store_id=? AND t.status='published' LIMIT 1",
            [$topicId, $storeId],
        );
        if (!is_array($topic)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.temu_ne_znaideno'));
        }
        if ((int) ($topic['customer_id'] ?? 0) !== $customerId) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.solution_author_only'));
        }
        $ok = (bool) $this->connection->fetchOne("SELECT 1 FROM mc_forum_post WHERE id=? AND topic_id=? AND status='published' LIMIT 1", [$postId, $topicId]);
        if (!$ok) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.povidomlennia_ne_znaideno'));
        }
        $same = (int) ($topic['solved_post_id'] ?? 0) === $postId;
        $this->connection->update('mc_forum_topic', ['solved_post_id' => $same ? null : $postId], ['id' => $topicId]);

        return !$same;
    }

    /** Remembers that a signed-in member is on the forum now (for "who is online"). Writes at most once a minute. */
    public function touch(int $customerId): void
    {
        $this->connection->executeStatement(
            'UPDATE mc_customer SET last_seen_at=? WHERE id=? AND (last_seen_at IS NULL OR last_seen_at<?)',
            [$this->now(), $customerId, (new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')],
        );
    }

    /** @return array{topics:int,posts:int,members:int,newest:?array{id:int,nickname:string},online:list<array{id:int,nickname:string}>} */
    public function overview(int $storeId): array
    {
        $topics = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND t.status='published'", [$storeId]);
        $posts = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND p.status='published' AND t.status='published'", [$storeId]);
        $members = (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT p.customer_id) FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND p.customer_id IS NOT NULL', [$storeId]);
        $newest = $this->connection->fetchAssociative(
            "SELECT fp.customer_id AS id,fp.nickname FROM mc_forum_profile fp WHERE fp.store_id=? AND fp.nickname NOT LIKE 'member-%' ORDER BY fp.id DESC LIMIT 1",
            [$storeId],
        );
        $online = $this->connection->fetchAllAssociative(
            "SELECT fp.customer_id AS id,fp.nickname FROM mc_forum_profile fp JOIN mc_customer c ON c.id=fp.customer_id
             WHERE fp.store_id=? AND c.last_seen_at>=? ORDER BY c.last_seen_at DESC LIMIT 30",
            [$storeId, (new DateTimeImmutable('-5 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')],
        );

        return [
            'topics' => $topics,
            'posts' => $posts,
            'members' => $members,
            'newest' => is_array($newest) ? ['id' => (int) $newest['id'], 'nickname' => (string) $newest['nickname']] : null,
            'online' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nickname' => (string) $r['nickname']], $online),
        ];
    }

    /**
     * Posts, reputation and rank of several members at once.
     *
     * @param list<int> $customerIds
     * @return array<int,array{posts:int,reputation:int,rank:string,avatar:?string}>
     */
    public function memberCards(int $storeId, array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $posts = $this->connection->fetchAllKeyValue(
            "SELECT p.customer_id,COUNT(*) FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id
             WHERE b.store_id=? AND p.status='published' AND p.customer_id IN ({$in}) GROUP BY p.customer_id",
            [$storeId, ...$ids],
        );
        $votes = $this->connection->fetchAllKeyValue(
            "SELECT p.customer_id,SUM(CASE WHEN r.reaction IN ('like','up') THEN 1 WHEN r.reaction='down' THEN -1 ELSE 0 END)
             FROM mc_forum_reaction r JOIN mc_forum_post p ON p.id=r.post_id JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id
             WHERE b.store_id=? AND p.customer_id IN ({$in}) GROUP BY p.customer_id",
            [$storeId, ...$ids],
        );
        $solutions = $this->connection->fetchAllKeyValue(
            "SELECT p.customer_id,COUNT(*) FROM mc_forum_topic t JOIN mc_forum_post p ON p.id=t.solved_post_id JOIN mc_forum_board b ON b.id=t.board_id
             WHERE b.store_id=? AND p.customer_id IN ({$in}) GROUP BY p.customer_id",
            [$storeId, ...$ids],
        );
        $avatars = $this->connection->fetchAllKeyValue("SELECT customer_id,avatar_url FROM mc_forum_profile WHERE store_id=? AND customer_id IN ({$in})", [$storeId, ...$ids]);
        $out = [];
        foreach ($ids as $id) {
            $count = (int) ($posts[$id] ?? 0);
            $reputation = (int) ($votes[$id] ?? 0) + 5 * (int) ($solutions[$id] ?? 0);
            $avatar = trim((string) ($avatars[$id] ?? ''));
            $out[$id] = ['posts' => $count, 'reputation' => $reputation, 'rank' => $this->rank($count + 3 * max(0, $reputation)), 'avatar' => $avatar !== '' ? $avatar : null];
        }

        return $out;
    }

    private function rank(int $points): string
    {
        foreach (self::RANK_POINTS as $level => $need) {
            if ($points >= $need) {
                return self::RANKS[$level];
            }
        }

        return 'newbie';
    }

    private function myVote(int $postId, int $customerId): int
    {
        $reaction = $this->connection->fetchOne("SELECT reaction FROM mc_forum_reaction WHERE post_id=? AND customer_id=? AND reaction IN ('like','up','down') LIMIT 1", [$postId, $customerId]);

        return $reaction === 'down' ? -1 : ($reaction === false ? 0 : 1);
    }

    private function score(int $postId): int
    {
        return (int) $this->connection->fetchOne("SELECT COALESCE(SUM(CASE WHEN reaction IN ('like','up') THEN 1 WHEN reaction='down' THEN -1 ELSE 0 END),0) FROM mc_forum_reaction WHERE post_id=?", [$postId]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
