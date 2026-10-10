<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Commerce\Core\I18n\StorefrontUiTranslator;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Doctrine\DBAL\Connection;

final readonly class ForumNotificationService
{
    public function __construct(
        private Connection $connection,
        private NotificationOutbox $notifications,
        private StorefrontUiTranslator $translator,
        private string $publicBaseUrl,
    ) {
    }

    public function notifyPublishedReply(int $storeId, int $postId): void
    {
        $post = $this->connection->fetchAssociative(
            "SELECT p.id,p.customer_id,p.body_text,t.id AS topic_id,t.title,t.slug,b.slug AS board_slug
             FROM mc_forum_post p
             JOIN mc_forum_topic t ON t.id=p.topic_id
             JOIN mc_forum_board b ON b.id=t.board_id
             WHERE p.id=? AND b.store_id=? AND p.status='published' AND t.status='published' LIMIT 1",
            [$postId, $storeId],
        );
        if (!is_array($post)) {
            return;
        }

        $subscribers = $this->connection->fetchAllAssociative(
            "SELECT s.customer_id,c.email,c.locale,c.display_name
             FROM mc_forum_subscription s
             JOIN mc_customer c ON c.id=s.customer_id
             WHERE s.store_id=? AND s.topic_id=? AND c.status='active'
               AND s.customer_id<>?
             ORDER BY s.customer_id",
            [$storeId, (int) $post['topic_id'], (int) ($post['customer_id'] ?? 0)],
        );

        $this->notifyMentions($storeId, $post, $subscribers);

        $base = rtrim($this->publicBaseUrl, '/');
        $url = $base . '/forum/t/' . (int) $post['topic_id'] . '/' . rawurlencode((string) $post['slug']) . '#post-' . $postId;
        foreach ($subscribers as $subscriber) {
            $email = trim((string) ($subscriber['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $locale = trim((string) ($subscriber['locale'] ?? '')) ?: 'uk-UA';
            $subject = $this->translator->translate('forum_new_reply_subject', $locale, ['topic' => (string) $post['title']]);
            $text = $this->translator->translate('forum_new_reply_text', $locale, ['topic' => (string) $post['title'], 'url' => $url]);
            $this->notifications->enqueue(
                NotificationChannel::Email,
                new NotificationMessage(
                    'forum.reply.published',
                    $subject,
                    $text,
                    [
                        'locale' => $locale,
                        'topic_id' => (int) $post['topic_id'],
                        'topic_title' => (string) $post['title'],
                        'post_id' => $postId,
                        'url' => $url,
                    ],
                    'generic',
                ),
                $email,
                null,
                'forum-reply:' . $postId . ':customer:' . (int) $subscriber['customer_id'],
            );
        }
    }

    /** Tells the author that a message was hidden, why, and quotes it so nothing is lost. */
    public function notifyPostHidden(int $storeId, int $postId, ?string $reason): void
    {
        $post = $this->connection->fetchAssociative(
            "SELECT p.id,p.body_text,t.id AS topic_id,t.title,t.slug,c.email,c.locale
             FROM mc_forum_post p
             JOIN mc_forum_topic t ON t.id=p.topic_id
             JOIN mc_forum_board b ON b.id=t.board_id
             JOIN mc_customer c ON c.id=p.customer_id AND c.status='active'
             WHERE p.id=? AND b.store_id=? LIMIT 1",
            [$postId, $storeId],
        );
        $email = is_array($post) ? trim((string) ($post['email'] ?? '')) : '';
        if ($email === '') {
            return;
        }
        $locale = trim((string) ($post['locale'] ?? '')) ?: 'uk-UA';
        $url = rtrim($this->publicBaseUrl, '/') . '/forum/t/' . (int) $post['topic_id'] . '/' . rawurlencode((string) $post['slug']) . '#post-' . $postId;
        $reason = trim((string) $reason) !== '' ? (string) $reason : $this->translator->translate('forum_hidden_no_reason', $locale);
        $quote = mb_substr((string) $post['body_text'], 0, 600, 'UTF-8');
        $this->notifications->enqueue(
            NotificationChannel::Email,
            new NotificationMessage(
                'forum.post.hidden',
                $this->translator->translate('forum_post_hidden_subject', $locale, ['topic' => (string) $post['title']]),
                $this->translator->translate('forum_post_hidden_text', $locale, ['topic' => (string) $post['title'], 'reason' => $reason, 'quote' => $quote, 'url' => $url]),
                ['locale' => $locale, 'topic_id' => (int) $post['topic_id'], 'post_id' => $postId, 'url' => $url],
                'generic',
            ),
            $email,
            null,
            'forum-hidden:' . $postId . ':' . substr(sha1($reason), 0, 12),
        );
    }

    /** Lets the moderators of the topic know that a member reported one of its messages. */
    public function notifyPostReported(int $storeId, int $postId, string $reason): void
    {
        $post = $this->connection->fetchAssociative(
            "SELECT p.id,t.id AS topic_id,t.title,t.slug
             FROM mc_forum_post p
             JOIN mc_forum_topic t ON t.id=p.topic_id
             JOIN mc_forum_board b ON b.id=t.board_id
             WHERE p.id=? AND b.store_id=? LIMIT 1",
            [$postId, $storeId],
        );
        if (!is_array($post)) {
            return;
        }
        $moderators = $this->connection->fetchAllAssociative(
            "SELECT m.customer_id,c.email,c.locale FROM mc_forum_topic_moderator m JOIN mc_customer c ON c.id=m.customer_id AND c.status='active' WHERE m.topic_id=? LIMIT 20",
            [(int) $post['topic_id']],
        );
        $url = rtrim($this->publicBaseUrl, '/') . '/forum/t/' . (int) $post['topic_id'] . '/' . rawurlencode((string) $post['slug']) . '#post-' . $postId;
        foreach ($moderators as $moderator) {
            $email = trim((string) ($moderator['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $locale = trim((string) ($moderator['locale'] ?? '')) ?: 'uk-UA';
            $this->notifications->enqueue(
                NotificationChannel::Email,
                new NotificationMessage(
                    'forum.post.reported',
                    $this->translator->translate('forum_post_reported_subject', $locale, ['topic' => (string) $post['title']]),
                    $this->translator->translate('forum_post_reported_text', $locale, ['topic' => (string) $post['title'], 'reason' => $reason, 'url' => $url]),
                    ['locale' => $locale, 'topic_id' => (int) $post['topic_id'], 'post_id' => $postId, 'url' => $url],
                    'generic',
                ),
                $email,
                null,
                'forum-reported:' . $postId . ':' . date('YmdH') . ':customer:' . (int) $moderator['customer_id'],
            );
        }
    }

    /**
     * @param array<string,mixed> $post
     * @param list<array<string,mixed>> $subscribers
     */
    private function notifyMentions(int $storeId, array $post, array $subscribers): void
    {
        if (preg_match_all('/(?:^|[\s(])@([\p{L}\p{N}_-]{2,64})/u', (string) ($post['body_text'] ?? ''), $found) < 1) {
            return;
        }
        $names = array_values(array_unique(array_map(static fn (string $n): string => mb_strtolower($n, 'UTF-8'), $found[1])));
        $in = implode(',', array_fill(0, count($names), '?'));
        $members = $this->connection->fetchAllAssociative(
            "SELECT p.customer_id,c.email,c.locale
             FROM mc_forum_profile p JOIN mc_customer c ON c.id=p.customer_id
             WHERE p.store_id=? AND c.status='active' AND LOWER(p.nickname) IN ({$in}) LIMIT 20",
            [$storeId, ...$names],
        );
        $already = array_map(static fn (array $s): int => (int) $s['customer_id'], $subscribers);
        $postId = (int) $post['id'];
        $url = rtrim($this->publicBaseUrl, '/') . '/forum/t/' . (int) $post['topic_id'] . '/' . rawurlencode((string) $post['slug']) . '#post-' . $postId;
        foreach ($members as $member) {
            $id = (int) $member['customer_id'];
            $email = trim((string) ($member['email'] ?? ''));
            if ($email === '' || $id === (int) ($post['customer_id'] ?? 0) || in_array($id, $already, true)) {
                continue;
            }
            $locale = trim((string) ($member['locale'] ?? '')) ?: 'uk-UA';
            $this->notifications->enqueue(
                NotificationChannel::Email,
                new NotificationMessage(
                    'forum.mention',
                    $this->translator->translate('forum_mention_subject', $locale, ['topic' => (string) $post['title']]),
                    $this->translator->translate('forum_mention_text', $locale, ['topic' => (string) $post['title'], 'url' => $url]),
                    ['locale' => $locale, 'topic_id' => (int) $post['topic_id'], 'post_id' => $postId, 'url' => $url],
                    'generic',
                ),
                $email,
                null,
                'forum-mention:' . $postId . ':customer:' . $id,
            );
        }
    }
}
