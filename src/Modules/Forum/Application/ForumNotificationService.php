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
}
