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
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function boards(int $storeId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT b.id,b.slug,b.name,b.description,b.sort_order,
                (SELECT COUNT(*) FROM mc_forum_topic t WHERE t.board_id=b.id AND t.status='published') AS topic_count,
                (SELECT COUNT(*) FROM mc_forum_post p JOIN mc_forum_topic t2 ON t2.id=p.topic_id WHERE t2.board_id=b.id AND t2.status='published' AND p.status='published') AS post_count,
                (SELECT MAX(p2.published_at) FROM mc_forum_post p2 JOIN mc_forum_topic t3 ON t3.id=p2.topic_id WHERE t3.board_id=b.id AND t3.status='published' AND p2.status='published') AS last_post_at
             FROM mc_forum_board b
             WHERE b.store_id=? AND b.status='active'
             ORDER BY b.sort_order,b.id",
            [$storeId],
        );
    }

    /** @return array<string,mixed>|null */
    public function board(int $storeId, string $slug): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id,slug,name,description,sort_order FROM mc_forum_board WHERE store_id=? AND slug=? AND status='active' LIMIT 1",
            [$storeId, $slug],
        );
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function topics(int $boardId, int $page = 1, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $offset = (max(1, $page) - 1) * $limit;
        return $this->connection->fetchAllAssociative(
            "SELECT t.id,t.title,t.slug,t.author_name,t.is_pinned,t.is_locked,t.created_at,t.published_at,t.last_post_at,
                (SELECT COUNT(*) FROM mc_forum_post p WHERE p.topic_id=t.id AND p.status='published') AS post_count
             FROM mc_forum_topic t
             WHERE t.board_id=? AND t.status='published'
             ORDER BY t.is_pinned DESC,COALESCE(t.last_post_at,t.published_at,t.created_at) DESC,t.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$boardId],
        );
    }

    /** @return array<string,mixed>|null */
    public function topic(int $storeId, int $topicId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT t.id,t.title,t.slug,t.author_name,t.is_pinned,t.is_locked,t.created_at,t.published_at,b.id AS board_id,b.slug AS board_slug,b.name AS board_name
             FROM mc_forum_topic t
             JOIN mc_forum_board b ON b.id=t.board_id
             WHERE t.id=? AND t.status='published' AND b.store_id=? AND b.status='active' LIMIT 1",
            [$topicId, $storeId],
        );
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function posts(int $topicId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id,author_name,body_text,created_at,published_at FROM mc_forum_post WHERE topic_id=? AND status='published' ORDER BY id ASC",
            [$topicId],
        );
    }

    public function createTopic(int $storeId, string $boardSlug, string $author, string $title, string $body): int
    {
        $author = $this->plain($author, 120, \Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.vkazhit_imia'));
        $title = $this->plain($title, 240, \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.vkazhit_temu'));
        $body = $this->body($body);
        $board = $this->board($storeId, $boardSlug);
        if ($board === null) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.application.forumservice.rozdil_forumu_ne_znaideno'));
        }
        $slugger = new AsciiSlugger('uk');
        $slug = mb_strtolower((string) $slugger->slug($title), 'UTF-8');
        if ($slug === '') {
            $slug = 'topic';
        }
        $now = $this->now();

        return $this->connection->transactional(function (Connection $db) use ($board, $author, $title, $body, $slug, $now): int {
            $db->insert('mc_forum_topic', [
                'public_id' => $this->publicIds->binary(),
                'board_id' => (int) $board['id'],
                'title' => $title,
                'slug' => mb_substr($slug, 0, 240, 'UTF-8'),
                'author_name' => $author,
                'status' => 'pending',
                'is_pinned' => 0,
                'is_locked' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'published_at' => null,
                'last_post_at' => null,
            ]);
            $topicId = (int) $db->lastInsertId();
            $db->insert('mc_forum_post', [
                'public_id' => $this->publicIds->binary(),
                'topic_id' => $topicId,
                'author_name' => $author,
                'body_text' => $body,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
                'published_at' => null,
            ]);
            return $topicId;
        });
    }

    public function createReply(int $storeId, int $topicId, string $author, string $body): int
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
        $now = $this->now();
        $this->connection->insert('mc_forum_post', [
            'public_id' => $this->publicIds->binary(),
            'topic_id' => $topicId,
            'author_name' => $author,
            'body_text' => $body,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => null,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /** @return array{boards:list<array<string,mixed>>,topics:list<array<string,mixed>>,posts:list<array<string,mixed>>} */
    public function moderationQueue(int $storeId): array
    {
        $boards = $this->connection->fetchAllAssociative(
            'SELECT id,slug,name,description,status,sort_order,created_at FROM mc_forum_board WHERE store_id=? ORDER BY sort_order,id',
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
        return ['boards' => $boards, 'topics' => $topics, 'posts' => $posts];
    }

    public function createBoard(int $storeId, string $name, string $slug, string $description, int $sortOrder): void
    {
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
            'description' => $description !== '' ? $description : null, 'status' => 'active', 'sort_order' => $sortOrder,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function moderateTopic(int $storeId, int $topicId, string $action): void
    {
        $allowed = ['approve', 'reject', 'lock', 'unlock'];
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
