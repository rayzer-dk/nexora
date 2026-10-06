<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** What a shop owner does to other people's content: write as the shop team, hide, restore or delete topics and messages. */
final readonly class ForumStaffService
{
    public function __construct(
        private Connection $connection,
        private PublicIdFactory $publicIds,
        private ForumMediaService $media,
    ) {
    }

    public function createTopic(int $storeId, int $boardId, string $author, string $title, string $body, bool $pinned): int
    {
        $title = trim(mb_substr(strip_tags($title), 0, 240, 'UTF-8'));
        $body = trim(mb_substr(str_replace("\0", '', $body), 0, 20000, 'UTF-8'));
        $author = trim(mb_substr(strip_tags($author), 0, 120, 'UTF-8')) ?: 'Team';
        if ($title === '' || mb_strlen($body, 'UTF-8') < 3) {
            throw new \DomainException(CanonicalUiText::get('php.modules.forum.application.forumservice.vkazhit_temu'));
        }
        if (!$this->connection->fetchOne('SELECT 1 FROM mc_forum_board WHERE id=? AND store_id=?', [$boardId, $storeId])) {
            throw new \DomainException(CanonicalUiText::get('php.modules.forum.application.forumservice.rozdil_forumu_ne_znaideno'));
        }
        $slug = mb_substr(mb_strtolower((string) (new AsciiSlugger('uk'))->slug($title), 'UTF-8'), 0, 240, 'UTF-8') ?: 'topic';
        $now = $this->now();

        return $this->connection->transactional(function (Connection $db) use ($boardId, $author, $title, $body, $slug, $now, $pinned): int {
            $db->insert('mc_forum_topic', [
                'public_id' => $this->publicIds->binary(), 'board_id' => $boardId, 'title' => $title, 'slug' => $slug, 'author_name' => $author,
                'status' => 'published', 'is_pinned' => $pinned ? 1 : 0, 'is_locked' => 0,
                'created_at' => $now, 'updated_at' => $now, 'published_at' => $now, 'last_post_at' => $now,
            ]);
            $topicId = (int) $db->lastInsertId();
            $this->insertPost($db, $topicId, $author, $body, 'published', $now);

            return $topicId;
        });
    }

    public function reply(int $storeId, int $topicId, string $author, string $body): int
    {
        $body = trim(mb_substr(str_replace("\0", '', $body), 0, 20000, 'UTF-8'));
        if (mb_strlen($body, 'UTF-8') < 3 || $this->topicRow($storeId, $topicId) === null) {
            throw new \DomainException(CanonicalUiText::get('php.modules.forum.application.forumservice.temu_ne_znaideno'));
        }
        $now = $this->now();
        $postId = $this->insertPost($this->connection, $topicId, trim(mb_substr(strip_tags($author), 0, 120, 'UTF-8')) ?: 'Team', $body, 'published', $now);
        $this->connection->update('mc_forum_topic', ['last_post_at' => $now, 'updated_at' => $now], ['id' => $topicId]);

        return $postId;
    }

    /** @param 'hide'|'restore'|'delete' $action */
    public function topicAction(int $storeId, int $topicId, string $action): void
    {
        if ($this->topicRow($storeId, $topicId) === null) {
            throw new \DomainException(CanonicalUiText::get('php.modules.forum.application.forumservice.temu_ne_znaideno'));
        }
        $now = $this->now();
        if ($action === 'delete') {
            $this->connection->transactional(function (Connection $db) use ($topicId): void {
                $ids = array_map('intval', $db->fetchFirstColumn('SELECT id FROM mc_forum_post WHERE topic_id=?', [$topicId]));
                $this->media->deleteForPosts($ids);
                if ($ids !== []) {
                    $in = implode(',', $ids);
                    $db->executeStatement("DELETE FROM mc_forum_reaction WHERE post_id IN ({$in})");
                    $db->executeStatement("DELETE FROM mc_forum_report WHERE post_id IN ({$in})");
                }
                $poll = $db->fetchOne('SELECT id FROM mc_forum_poll WHERE topic_id=?', [$topicId]);
                if ($poll !== false) {
                    $db->executeStatement('DELETE FROM mc_forum_poll_vote WHERE poll_id=?', [$poll]);
                    $db->executeStatement('DELETE FROM mc_forum_poll_option WHERE poll_id=?', [$poll]);
                    $db->executeStatement('DELETE FROM mc_forum_poll WHERE id=?', [$poll]);
                }
                $db->executeStatement('DELETE FROM mc_forum_subscription WHERE topic_id=?', [$topicId]);
                $db->executeStatement('DELETE FROM mc_forum_topic_read WHERE topic_id=?', [$topicId]);
                $db->executeStatement('DELETE FROM mc_forum_post WHERE topic_id=?', [$topicId]);
                $db->executeStatement('DELETE FROM mc_forum_topic WHERE id=?', [$topicId]);
            });

            return;
        }
        $this->connection->update('mc_forum_topic', ['status' => $action === 'hide' ? 'hidden' : 'published', 'updated_at' => $now], ['id' => $topicId]);
    }

    /** @param 'hide'|'restore'|'delete' $action */
    public function postAction(int $storeId, int $postId, string $action): int
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.id,p.topic_id,(SELECT MIN(id) FROM mc_forum_post f WHERE f.topic_id=p.topic_id) AS first_id
             FROM mc_forum_post p JOIN mc_forum_topic t ON t.id=p.topic_id JOIN mc_forum_board b ON b.id=t.board_id WHERE p.id=? AND b.store_id=?',
            [$postId, $storeId],
        );
        if (!is_array($row)) {
            throw new \DomainException(CanonicalUiText::get('php.modules.forum.application.forumservice.povidomlennia_ne_znaideno'));
        }
        $topicId = (int) $row['topic_id'];
        if ((int) $row['first_id'] === $postId && $action !== 'restore') {
            // The opening message is the topic itself.
            $this->topicAction($storeId, $topicId, $action);

            return $topicId;
        }
        if ($action === 'delete') {
            $this->media->deleteForPosts([$postId]);
            $this->connection->executeStatement('DELETE FROM mc_forum_reaction WHERE post_id=?', [$postId]);
            $this->connection->executeStatement('DELETE FROM mc_forum_report WHERE post_id=?', [$postId]);
            $this->connection->executeStatement('UPDATE mc_forum_topic SET solved_post_id=NULL WHERE id=? AND solved_post_id=?', [$topicId, $postId]);
            $this->connection->executeStatement('DELETE FROM mc_forum_post WHERE id=?', [$postId]);

            return $topicId;
        }
        $this->connection->update('mc_forum_post', ['status' => $action === 'hide' ? 'hidden' : 'published', 'updated_at' => $this->now()], ['id' => $postId]);

        return $topicId;
    }

    /** A member the shop trusts with one topic: they can hide and delete messages in it. */
    public function addModerator(int $storeId, int $topicId, string $who): void
    {
        if ($this->topicRow($storeId, $topicId) === null) {
            throw new \DomainException(CanonicalUiText::get('php.modules.forum.application.forumservice.temu_ne_znaideno'));
        }
        $who = trim($who);
        $customerId = ctype_digit($who)
            ? (int) $this->connection->fetchOne("SELECT id FROM mc_customer WHERE id=? AND status='active'", [(int) $who])
            : (int) $this->connection->fetchOne("SELECT customer_id FROM mc_forum_profile WHERE store_id=? AND LOWER(nickname)=LOWER(?)", [$storeId, $who]);
        if ($customerId < 1) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.customer_missing'));
        }
        $this->connection->executeStatement('INSERT IGNORE INTO mc_forum_topic_moderator(topic_id,customer_id,created_at) VALUES (?,?,?)', [$topicId, $customerId, $this->now()]);
    }

    public function removeModerator(int $storeId, int $topicId, int $customerId): void
    {
        if ($this->topicRow($storeId, $topicId) !== null) {
            $this->connection->executeStatement('DELETE FROM mc_forum_topic_moderator WHERE topic_id=? AND customer_id=?', [$topicId, $customerId]);
        }
    }

    /** @return list<array{customer_id:int,nickname:string}> */
    public function moderators(int $topicId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.customer_id,COALESCE((SELECT p.nickname FROM mc_forum_profile p WHERE p.customer_id=m.customer_id LIMIT 1),CONCAT('#',m.customer_id)) AS nickname FROM mc_forum_topic_moderator m WHERE m.topic_id=? ORDER BY m.created_at",
            [$topicId],
        );

        return array_map(static fn (array $r): array => ['customer_id' => (int) $r['customer_id'], 'nickname' => (string) $r['nickname']], $rows);
    }

    public function isModerator(int $topicId, int $customerId): bool
    {
        return (bool) $this->connection->fetchOne('SELECT 1 FROM mc_forum_topic_moderator WHERE topic_id=? AND customer_id=?', [$topicId, $customerId]);
    }

    /** Hide or delete one message by a topic moderator; the opening message and other topics stay out of reach. */
    public function moderatorPostAction(int $storeId, int $topicId, int $postId, int $moderatorId, string $action): void
    {
        if (!in_array($action, ['hide', 'delete'], true) || !$this->isModerator($topicId, $moderatorId)) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.not_moderator'));
        }
        $row = $this->connection->fetchAssociative('SELECT p.topic_id,(SELECT MIN(id) FROM mc_forum_post f WHERE f.topic_id=p.topic_id) AS first_id FROM mc_forum_post p WHERE p.id=?', [$postId]);
        if (!is_array($row) || (int) $row['topic_id'] !== $topicId || (int) $row['first_id'] === $postId) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.not_moderator'));
        }
        $this->postAction($storeId, $postId, $action);
    }

    /** @return array{topic:array<string,mixed>,posts:list<array<string,mixed>>}|null */
    public function topicWithAllPosts(int $storeId, int $topicId): ?array
    {
        $topic = $this->topicRow($storeId, $topicId);
        if ($topic === null) {
            return null;
        }
        $posts = $this->connection->fetchAllAssociative(
            'SELECT id,customer_id,author_name,body_text,status,created_at,
                    (SELECT COUNT(*) FROM mc_forum_attachment a WHERE a.post_id=p.id) AS pictures
             FROM mc_forum_post p WHERE topic_id=? ORDER BY id LIMIT 500',
            [$topicId],
        );

        return ['topic' => $topic, 'posts' => $posts];
    }

    /** @return array<string,mixed>|null */
    private function topicRow(int $storeId, int $topicId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT t.id,t.title,t.slug,t.status,t.is_pinned,t.is_locked,t.author_name,b.name AS board_name,b.slug AS board_slug
             FROM mc_forum_topic t JOIN mc_forum_board b ON b.id=t.board_id WHERE t.id=? AND b.store_id=?',
            [$topicId, $storeId],
        );

        return is_array($row) ? $row : null;
    }

    private function insertPost(Connection $db, int $topicId, string $author, string $body, string $status, string $now): int
    {
        $db->insert('mc_forum_post', [
            'public_id' => $this->publicIds->binary(), 'topic_id' => $topicId, 'author_name' => $author, 'body_text' => $body,
            'status' => $status, 'created_at' => $now, 'updated_at' => $now, 'published_at' => $now,
        ]);

        return (int) $db->lastInsertId();
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
