<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\AsciiSlugger;

final readonly class ForumService
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private ForumSettings $settings,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function boards(int $storeId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT b.id,b.parent_id,b.slug,b.name,b.description,b.sort_order,
                (SELECT COUNT(*) FROM mc_forum_topic t WHERE t.board_id=b.id AND t.status='published') AS topic_count,
                (SELECT COUNT(*) FROM mc_forum_post p JOIN mc_forum_topic t2 ON t2.id=p.topic_id WHERE t2.board_id=b.id AND t2.status='published' AND p.status='published') AS post_count,
                (SELECT MAX(p2.published_at) FROM mc_forum_post p2 JOIN mc_forum_topic t3 ON t3.id=p2.topic_id WHERE t3.board_id=b.id AND t3.status='published' AND p2.status='published') AS last_post_at
             FROM mc_forum_board b
             WHERE b.store_id=? AND b.status='active'
             ORDER BY b.sort_order,b.id",
            [$storeId],
        );
        foreach ($rows as &$row) {
            $last = $this->connection->fetchAssociative(
                "SELECT t.id,t.title,t.slug,COALESCE(t.last_post_at,t.published_at,t.created_at) AS active_at,
                        (SELECT COALESCE(NULLIF(fp.nickname,''),p.author_name) FROM mc_forum_post p LEFT JOIN mc_forum_profile fp ON fp.customer_id=p.customer_id AND fp.store_id=?
                         WHERE p.topic_id=t.id AND p.status='published' ORDER BY p.id DESC LIMIT 1) AS last_author
                 FROM mc_forum_topic t WHERE t.board_id=? AND t.status='published'
                 ORDER BY COALESCE(t.last_post_at,t.published_at,t.created_at) DESC,t.id DESC LIMIT 1",
                [$storeId, (int) $row['id']],
            );
            $row['last_topic'] = is_array($last) ? $last : null;
        }
        unset($row);

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function board(int $storeId, string $slug): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT b.id,b.parent_id,b.slug,b.name,b.description,b.sort_order,pb.slug AS parent_slug,pb.name AS parent_name
             FROM mc_forum_board b LEFT JOIN mc_forum_board pb ON pb.id=b.parent_id
             WHERE b.store_id=? AND b.slug=? AND b.status='active' LIMIT 1",
            [$storeId, $slug],
        );
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function topics(int $boardId, int $page = 1, int $limit = 30, ?int $customerId = null, string $sort = 'activity'): array
    {
        $limit = max(1, min(100, $limit));
        $offset = (max(1, $page) - 1) * $limit;
        $order = match ($sort) {
            'new' => 'COALESCE(t.published_at,t.created_at) DESC,t.id DESC',
            'replies' => 'post_count DESC,t.id DESC',
            'views' => 't.views_count DESC,t.id DESC',
            default => 'COALESCE(t.last_post_at,t.published_at,t.created_at) DESC,t.id DESC',
        };

        return $this->connection->fetchAllAssociative(
            "SELECT t.id,t.customer_id,t.title,t.slug,t.is_pinned,t.is_locked,t.views_count,t.solved_post_id,t.created_at,t.published_at,
                CASE WHEN t.customer_id IS NOT NULL THEN COALESCE(NULLIF(fp.nickname,''),CONCAT('member-',LOWER(SUBSTRING(SHA2(c.public_id,256),1,12)),'-',LOWER(CONV(c.id,10,36)))) ELSE t.author_name END AS author_name,
                (SELECT COUNT(*) FROM mc_forum_post p WHERE p.topic_id=t.id AND p.status='published') AS post_count,
                COALESCE(t.last_post_at,t.published_at,t.created_at) AS active_at,
                (SELECT COALESCE(NULLIF(lfp.nickname,''),lp.author_name) FROM mc_forum_post lp LEFT JOIN mc_forum_profile lfp ON lfp.customer_id=lp.customer_id AND lfp.store_id=b0.store_id
                 WHERE lp.topic_id=t.id AND lp.status='published' ORDER BY lp.id DESC LIMIT 1) AS last_author,
                CASE WHEN ? <= 0 THEN 0 WHEN tr.id IS NULL OR tr.read_at < COALESCE(t.last_post_at,t.published_at,t.created_at) THEN 1 ELSE 0 END AS is_unread,
                CASE WHEN ? <= 0 THEN 0 WHEN sub.id IS NULL THEN 0 ELSE 1 END AS is_followed
             FROM mc_forum_topic t
             JOIN mc_forum_board b0 ON b0.id=t.board_id
             LEFT JOIN mc_customer c ON c.id=t.customer_id
             LEFT JOIN mc_forum_profile fp ON fp.customer_id=t.customer_id AND fp.store_id=b0.store_id
             LEFT JOIN mc_forum_topic_read tr ON tr.topic_id=t.id AND tr.customer_id=?
             LEFT JOIN mc_forum_subscription sub ON sub.topic_id=t.id AND sub.customer_id=?
             WHERE t.board_id=? AND t.status='published'
             ORDER BY t.is_pinned DESC,{$order}
             LIMIT {$limit} OFFSET {$offset}",
            [$customerId ?? 0, $customerId ?? 0, $customerId ?? 0, $customerId ?? 0, $boardId],
        );
    }

    /**
     * The most recently active published topics of the store, across all boards (for the forum home page).
     *
     * @return list<array<string,mixed>>
     */
    public function latestTopics(int $storeId, int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));

        return $this->connection->fetchAllAssociative(
            "SELECT t.id,t.title,t.slug,b.name AS board_name,b.slug AS board_slug,t.is_pinned,t.is_locked,t.views_count,
                    COALESCE(t.last_post_at,t.published_at,t.created_at) AS active_at,
                    (SELECT COUNT(*) FROM mc_forum_post p WHERE p.topic_id=t.id AND p.status='published') AS post_count
             FROM mc_forum_topic t
             JOIN mc_forum_board b ON b.id=t.board_id AND b.store_id=?
             WHERE t.status='published'
             ORDER BY COALESCE(t.last_post_at,t.published_at,t.created_at) DESC,t.id DESC
             LIMIT {$limit}",
            [$storeId],
        );
    }

    /** @return array<string,mixed>|null */
    public function topic(int $storeId, int $topicId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT t.id,t.customer_id,t.title,t.slug,CASE WHEN t.customer_id IS NOT NULL THEN COALESCE(NULLIF(fp.nickname,''),CONCAT('member-',LOWER(SUBSTRING(SHA2(c.public_id,256),1,12)),'-',LOWER(CONV(c.id,10,36)))) ELSE t.author_name END AS author_name,t.header_text,t.slow_mode_seconds,t.is_pinned,t.is_locked,t.views_count,t.solved_post_id,t.created_at,t.published_at,b.id AS board_id,b.slug AS board_slug,b.name AS board_name,
                (SELECT COUNT(*) FROM mc_forum_post p2 WHERE p2.topic_id=t.id AND p2.status='published') AS post_count
             FROM mc_forum_topic t
             JOIN mc_forum_board b ON b.id=t.board_id
             LEFT JOIN mc_customer c ON c.id=t.customer_id
             LEFT JOIN mc_forum_profile fp ON fp.customer_id=t.customer_id AND fp.store_id=b.store_id
             WHERE t.id=? AND t.status='published' AND b.store_id=? AND b.status='active' LIMIT 1",
            [$topicId, $storeId],
        );
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function posts(int $storeId, int $topicId, int $page = 1, int $limit = 30, ?int $customerId = null): array
    {
        $limit = max(1, min(100, $limit));
        $offset = (max(1, $page) - 1) * $limit;
        return $this->connection->fetchAllAssociative(
            "SELECT p.id,p.customer_id,CASE WHEN p.customer_id IS NOT NULL THEN COALESCE(NULLIF(fp.nickname,''),CONCAT('member-',LOWER(SUBSTRING(SHA2(c.public_id,256),1,12)),'-',LOWER(CONV(c.id,10,36)))) ELSE p.author_name END AS author_name,p.status,p.hidden_reason,p.body_text,p.created_at,p.published_at,p.edited_at,p.edit_count,\n                (SELECT COALESCE(SUM(CASE WHEN r.reaction IN ('like','up') THEN 1 WHEN r.reaction='down' THEN -1 ELSE 0 END),0) FROM mc_forum_reaction r WHERE r.post_id=p.id) AS score,\n                (SELECT CASE WHEN r2.reaction='down' THEN -1 ELSE 1 END FROM mc_forum_reaction r2 WHERE r2.post_id=p.id AND r2.customer_id=? AND r2.reaction IN ('like','up','down') LIMIT 1) AS my_vote\n             FROM mc_forum_post p\n             JOIN mc_forum_topic t ON t.id=p.topic_id\n             JOIN mc_forum_board b ON b.id=t.board_id\n             LEFT JOIN mc_customer c ON c.id=p.customer_id\n             LEFT JOIN mc_forum_profile fp ON fp.customer_id=p.customer_id AND fp.store_id=b.store_id\n             WHERE p.topic_id=? AND b.store_id=? AND (p.status='published' OR (p.status='hidden' AND p.customer_id=?)) ORDER BY p.id ASC LIMIT {$limit} OFFSET {$offset}",
            [$customerId ?? 0, $topicId, $storeId, $customerId ?? 0],
        );
    }

    public function createTopic(int $storeId, string $boardSlug, int $customerId, string $author, string $title, string $body): int
    {
        $author = $this->plain($author, 120, \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.vkazhit_imia'));
        $title = $this->plain($title, 240, \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.vkazhit_temu'));
        $body = $this->body($body);
        $board = $this->board($storeId, $boardSlug);
        if ($board === null) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.rozdil_forumu_ne_znaideno'));
        }
        $this->guardPosting($storeId, $customerId, $body, true);
        $slugger = new AsciiSlugger('uk');
        $slug = mb_strtolower((string) $slugger->slug($title), 'UTF-8');
        if ($slug === '') {
            $slug = 'topic';
        }
        $now = $this->now();
        $live = $this->autoPublish($storeId, $customerId, $body, true);
        $status = $live ? 'published' : 'pending';
        $livenow = $live ? $now : null;

        return $this->connection->transactional(function (Connection $db) use ($storeId, $board, $customerId, $author, $title, $body, $slug, $now, $status, $livenow): int {
            $db->insert('mc_forum_topic', [
                'public_id' => $this->publicIds->binary(),
                'board_id' => (int) $board['id'],
                'customer_id' => $customerId,
                'title' => $title,
                'slug' => mb_substr($slug, 0, 240, 'UTF-8'),
                'author_name' => $author,
                'status' => $status,
                'is_pinned' => 0,
                'is_locked' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'published_at' => $livenow,
                'last_post_at' => $livenow,
            ]);
            $topicId = (int) $db->lastInsertId();
            $db->insert('mc_forum_post', [
                'public_id' => $this->publicIds->binary(),
                'topic_id' => $topicId,
                'customer_id' => $customerId,
                'author_name' => $author,
                'body_text' => $body,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
                'published_at' => $livenow,
            ]);
            $db->insert('mc_forum_subscription', [
                'store_id' => $storeId,
                'topic_id' => $topicId,
                'customer_id' => $customerId,
                'created_at' => $now,
            ]);
            return $topicId;
        });
    }

    public function isPostPublished(int $postId): bool
    {
        return $this->connection->fetchOne('SELECT status FROM mc_forum_post WHERE id=?', [$postId]) === 'published';
    }

    private function autoPublish(int $storeId, int $customerId, string $body, bool $topic): bool
    {
        $config = $this->settings->all();
        $mode = $topic ? $config['topics_mode'] : $config['replies_mode'];
        if ($mode === 'moderate' || preg_match_all('~https?://|www\.~i', $body) > $config['max_links']) {
            return false;
        }
        if ($mode === 'authorized') {
            return true;
        }
        $approved = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id
             WHERE b.store_id=? AND p.customer_id=? AND p.status='published'",
            [$storeId, $customerId],
        );

        return $approved >= $config['trusted_after'];
    }

    public function firstPostId(int $topicId): int
    {
        return (int) $this->connection->fetchOne('SELECT MIN(id) FROM mc_forum_post WHERE topic_id=?', [$topicId]);
    }

    public function createReply(int $storeId, int $topicId, int $customerId, string $author, string $body): int
    {
        $author = $this->plain($author, 120, \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.vkazhit_imia'));
        $body = $this->body($body);
        $topic = $this->topic($storeId, $topicId);
        if ($topic === null) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.temu_forumu_ne_znaideno'));
        }
        if ((int) ($topic['is_locked'] ?? 0) === 1) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.tsia_tema_zakryta_dlia_novykh_vidpovidei'));
        }
        $this->guardPosting($storeId, $customerId, $body, false);
        $this->guardSlowMode($topicId, $customerId, (int) ($topic['slow_mode_seconds'] ?? 0));
        $now = $this->now();
        $live = $this->autoPublish($storeId, $customerId, $body, false);
        $this->connection->insert('mc_forum_post', [
            'public_id' => $this->publicIds->binary(),
            'topic_id' => $topicId,
            'customer_id' => $customerId,
            'author_name' => $author,
            'body_text' => $body,
            'status' => $live ? 'published' : 'pending',
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => $live ? $now : null,
        ]);
        $postId = (int) $this->connection->lastInsertId();
        if ($live) {
            $this->connection->update('mc_forum_topic', ['last_post_at' => $now, 'updated_at' => $now], ['id' => $topicId]);
        }
        $this->connection->executeStatement(
            'INSERT IGNORE INTO mc_forum_subscription(store_id,topic_id,customer_id,created_at) VALUES (?,?,?,?)',
            [$storeId, $topicId, $customerId, $now],
        );
        return $postId;
    }

    /** @return array{boards:list<array<string,mixed>>,topics:list<array<string,mixed>>,posts:list<array<string,mixed>>,published_topics:list<array<string,mixed>>} */
    public function moderationQueue(int $storeId): array
    {
        $boards = $this->connection->fetchAllAssociative(
            'SELECT b.id,b.parent_id,b.slug,b.name,b.description,b.status,b.sort_order,b.created_at,(SELECT COUNT(*) FROM mc_forum_topic t WHERE t.board_id=b.id) AS topic_count FROM mc_forum_board b WHERE b.store_id=? ORDER BY b.sort_order,b.id',
            [$storeId],
        );
        $topics = $this->connection->fetchAllAssociative(
            "SELECT t.id,t.title,t.author_name,t.status,t.created_at,b.name AS board_name FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND t.status='pending' ORDER BY t.id ASC LIMIT 100",
            [$storeId],
        );
        $posts = $this->connection->fetchAllAssociative(
            "SELECT p.id,p.topic_id,p.author_name,p.body_text,p.status,p.created_at,t.title AS topic_title FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id WHERE b.store_id=? AND p.status='pending' AND t.status='published' ORDER BY p.id ASC LIMIT 150",
            [$storeId],
        );
        $publishedTopics = $this->connection->fetchAllAssociative(
            "SELECT t.id,t.title,t.author_name,t.is_pinned,t.is_locked,t.views_count,t.last_post_at,b.name AS board_name
             FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id
             WHERE b.store_id=? AND t.status='published'
             ORDER BY COALESCE(t.last_post_at,t.published_at,t.created_at) DESC,t.id DESC LIMIT 100",
            [$storeId],
        );
        return ['boards' => $boards, 'topics' => $topics, 'posts' => $posts, 'published_topics' => $publishedTopics];
    }

    public function createBoard(int $storeId, string $name, string $slug, string $description, int $sortOrder, ?int $parentId = null): void
    {
        $parentId = $this->validParent($storeId, $parentId, null);
        $name = $this->plain($name, 190, \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.vkazhit_nazvu_rozdilu'));
        $description = trim(strip_tags($description));
        $description = mb_substr($description, 0, 1000, 'UTF-8');
        $slugger = new AsciiSlugger('uk');
        $slug = trim($slug);
        $slug = $slug !== '' ? mb_strtolower((string) $slugger->slug($slug), 'UTF-8') : mb_strtolower((string) $slugger->slug($name), 'UTF-8');
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,159}$/', $slug)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.nekorektna_adresa_rozdilu_forumu'));
        }
        $now = $this->now();
        $this->connection->insert('mc_forum_board', [
            'public_id' => $this->publicIds->binary(), 'store_id' => $storeId, 'slug' => $slug, 'name' => $name,
            'description' => $description !== '' ? $description : null, 'status' => 'active', 'sort_order' => $sortOrder, 'parent_id' => $parentId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Rename a section, change its description, order or visibility (active/hidden). */
    public function updateBoard(int $storeId, int $boardId, string $name, string $description, int $sortOrder, string $status, ?int $parentId = null): void
    {
        $parentId = $this->validParent($storeId, $parentId, $boardId);
        $name = $this->plain($name, 190, \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.vkazhit_nazvu_rozdilu'));
        $description = mb_substr(trim(strip_tags($description)), 0, 1000, 'UTF-8');
        if (!in_array($status, ['active', 'hidden'], true)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.forum.board.bad_status'));
        }
        $changed = $this->connection->update('mc_forum_board', [
            'name' => $name, 'description' => $description !== '' ? $description : null, 'sort_order' => max(-100000, min(100000, $sortOrder)),
            'status' => $status, 'parent_id' => $parentId, 'updated_at' => $this->now(),
        ], ['id' => $boardId, 'store_id' => $storeId]);
        if ($changed === 0 && $this->connection->fetchOne('SELECT id FROM mc_forum_board WHERE id=? AND store_id=?', [$boardId, $storeId]) === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.forum.board.not_found'));
        }
    }

    /** Only a section without topics can be deleted; otherwise hide it. */
    public function deleteBoard(int $storeId, int $boardId): void
    {
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_forum_topic WHERE board_id=?', [$boardId]) > 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.forum.board.not_empty'));
        }
        $this->connection->delete('mc_forum_board', ['id' => $boardId, 'store_id' => $storeId]);
    }

    public function moderateTopic(int $storeId, int $topicId, string $action): void
    {
        $allowed = ['approve', 'reject', 'lock', 'unlock', 'pin', 'unpin'];
        if (!in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.335df6a2c54b'));
        }
        $row = $this->connection->fetchAssociative(
            'SELECT t.id,t.status,t.board_id FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE t.id=? AND b.store_id=? LIMIT 1',
            [$topicId, $storeId],
        );
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.temu_ne_znaideno'));
        }
        $now = $this->now();
        $this->connection->transactional(function (Connection $db) use ($topicId, $action, $now): void {
            if ($action === 'approve') {
                $db->update('mc_forum_topic', ['status' => 'published', 'published_at' => $now, 'updated_at' => $now, 'last_post_at' => $now], ['id' => $topicId]);
                $firstPostId = $db->fetchOne('SELECT id FROM mc_forum_post WHERE topic_id=? ORDER BY id ASC LIMIT 1', [$topicId]);
                if ($firstPostId !== false) {
                    $db->update('mc_forum_post', ['status' => 'published', 'published_at' => $now, 'updated_at' => $now], ['id' => (int) $firstPostId]);
                }
                return;
            }
            if ($action === 'reject') {
                $db->update('mc_forum_topic', ['status' => 'rejected', 'updated_at' => $now], ['id' => $topicId]);
                $db->executeStatement("UPDATE mc_forum_post SET status='rejected',updated_at=? WHERE topic_id=? AND status='pending'", [$now, $topicId]);
                return;
            }
            if ($action === 'pin' || $action === 'unpin') {
                $db->update('mc_forum_topic', ['is_pinned' => $action === 'pin' ? 1 : 0, 'updated_at' => $now], ['id' => $topicId]);
                return;
            }
            $db->update('mc_forum_topic', ['is_locked' => $action === 'lock' ? 1 : 0, 'updated_at' => $now], ['id' => $topicId]);
        });
    }

    public function moderatePost(int $storeId, int $postId, string $action): void
    {
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.335df6a2c54b'));
        }
        $row = $this->connection->fetchAssociative(
            'SELECT p.id,p.topic_id FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id WHERE p.id=? AND b.store_id=? LIMIT 1',
            [$postId, $storeId],
        );
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.povidomlennia_ne_znaideno'));
        }
        $now = $this->now();
        $status = $action === 'approve' ? 'published' : 'rejected';
        $this->connection->transactional(function (Connection $db) use ($row, $postId, $status, $now): void {
            $db->update('mc_forum_post', ['status' => $status, 'published_at' => $status === 'published' ? $now : null, 'updated_at' => $now], ['id' => $postId]);
            if ($status === 'published') {
                $db->update('mc_forum_topic', ['last_post_at' => $now, 'updated_at' => $now], ['id' => (int) $row['topic_id']]);
            }
        });
    }

    /** A subforum must hang under an existing top-level section of the same store; a section with subforums cannot become one. */
    private function validParent(int $storeId, ?int $parentId, ?int $boardId): ?int
    {
        if ($parentId === null || $parentId <= 0) {
            return null;
        }
        if ($boardId !== null && ($parentId === $boardId || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_forum_board WHERE parent_id=? AND store_id=?', [$boardId, $storeId]) > 0)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.board_parent_invalid'));
        }
        $ok = $this->connection->fetchOne('SELECT id FROM mc_forum_board WHERE id=? AND store_id=? AND parent_id IS NULL', [$parentId, $storeId]);
        if ($ok === false) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.board_parent_invalid'));
        }

        return $parentId;
    }

    /** Slow mode: a topic may allow one message per member every N seconds; the topic's moderators are exempt. */
    private function guardSlowMode(int $topicId, int $customerId, int $seconds): void
    {
        if ($seconds < 1) {
            return;
        }
        if ($this->connection->fetchOne('SELECT 1 FROM mc_forum_topic_moderator WHERE topic_id=? AND customer_id=?', [$topicId, $customerId])) {
            return;
        }
        $last = $this->connection->fetchOne('SELECT MAX(created_at) FROM mc_forum_post WHERE topic_id=? AND customer_id=?', [$topicId, $customerId]);
        if (!is_string($last) || $last === '') {
            return;
        }
        $wait = $seconds - (time() - (new DateTimeImmutable($last, new DateTimeZone('UTC')))->getTimestamp());
        if ($wait > 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.slow_mode', ['seconds' => (string) $wait]));
        }
    }

    private function guardPosting(int $storeId, int $customerId, string $body, bool $topic): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tenMinutesAgo = $now->modify('-10 minutes')->format('Y-m-d H:i:s.u');
        $hourAgo = $now->modify('-1 hour')->format('Y-m-d H:i:s.u');
        $dayAgo = $now->modify('-24 hours')->format('Y-m-d H:i:s.u');

        $recentPosts = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM mc_forum_post p
             JOIN mc_forum_topic t ON t.id=p.topic_id
             JOIN mc_forum_board b ON b.id=t.board_id
             WHERE b.store_id=? AND p.customer_id=? AND p.created_at>=?",
            [$storeId, $customerId, $tenMinutesAgo],
        );
        if ($recentPosts >= 5) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.post_rate'));
        }

        if ($topic) {
            $recentTopics = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM mc_forum_topic t
                 JOIN mc_forum_board b ON b.id=t.board_id
                 WHERE b.store_id=? AND t.customer_id=? AND t.created_at>=?",
                [$storeId, $customerId, $hourAgo],
            );
            if ($recentTopics >= 2) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.topic_rate'));
            }
        }

        $duplicate = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM mc_forum_post p
             JOIN mc_forum_topic t ON t.id=p.topic_id
             JOIN mc_forum_board b ON b.id=t.board_id
             WHERE b.store_id=? AND p.customer_id=? AND p.body_text=? AND p.created_at>=?
             LIMIT 1",
            [$storeId, $customerId, $body, $dayAgo],
        );
        if ($duplicate) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.duplicate_post'));
        }

        preg_match_all('#(?:https?://|www\.)#iu', $body, $matches);
        if (count($matches[0]) > 3) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.too_many_links'));
        }
    }

    private function plain(string $value, int $max, string $emptyMessage): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
        if ($value === '') {
            throw new \DomainException($emptyMessage);
        }
        return mb_substr($value, 0, $max, 'UTF-8');
    }

    private function body(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", strip_tags($value)));
        if (mb_strlen($value, 'UTF-8') < 3) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.povidomlennia_zanadto_korotke'));
        }
        return mb_substr($value, 0, 20000, 'UTF-8');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
