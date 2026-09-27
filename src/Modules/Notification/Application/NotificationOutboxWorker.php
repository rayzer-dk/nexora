<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class NotificationOutboxWorker
{
    private const MAX_ATTEMPTS = 5;
    private const STALE_LOCK_SECONDS = 900;

    public function __construct(
        private Connection $connection,
        private NotificationDispatcher $dispatcher,
    ) {
    }

    /** @return array{scanned:int,sent:int,retried:int,failed:int} */
    public function run(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $now = new DateTimeImmutable();
        $staleBefore = $now->sub(new DateInterval('PT' . self::STALE_LOCK_SECONDS . 'S'));

        $this->connection->executeStatement(
            'UPDATE mc_notification_outbox
             SET status = :pending, locked_at = NULL, lock_token = NULL
             WHERE status = :processing AND locked_at IS NOT NULL AND locked_at < :stale_before',
            [
                'pending' => 'pending',
                'processing' => 'processing',
                'stale_before' => $staleBefore->format('Y-m-d H:i:s.u'),
            ],
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, channel, notification_type, recipient, payload, attempts
             FROM mc_notification_outbox
             WHERE status = :status AND available_at <= :now
             ORDER BY available_at ASC, id ASC
             LIMIT ' . $limit,
            ['status' => 'pending', 'now' => $now->format('Y-m-d H:i:s.u')],
        );

        $stats = ['scanned' => count($rows), 'sent' => 0, 'retried' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $lockToken = random_bytes(16);
            $claimed = $this->connection->executeStatement(
                'UPDATE mc_notification_outbox
                 SET status = :processing, locked_at = :locked_at, lock_token = :lock_token
                 WHERE id = :id AND status = :pending',
                [
                    'processing' => 'processing',
                    'locked_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                    'lock_token' => $lockToken,
                    'id' => $id,
                    'pending' => 'pending',
                ],
            );
            if ($claimed !== 1) {
                continue;
            }

            try {
                $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
                $message = new NotificationMessage(
                    type: (string) $row['notification_type'],
                    subject: (string) ($payload['subject'] ?? ''),
                    text: (string) ($payload['text'] ?? ''),
                    context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
                    emailTemplate: (string) ($payload['email_template'] ?? 'generic'),
                );
                $this->dispatcher->send(NotificationChannel::from((string) $row['channel']), $message, (string) $row['recipient']);

                $this->connection->executeStatement(
                    'UPDATE mc_notification_outbox
                     SET status = :sent, sent_at = :sent_at, last_error = NULL, locked_at = NULL, lock_token = NULL
                     WHERE id = :id AND lock_token = :lock_token',
                    [
                        'sent' => 'sent',
                        'sent_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                        'id' => $id,
                        'lock_token' => $lockToken,
                    ],
                );
                ++$stats['sent'];
            } catch (Throwable $e) {
                $attempts = ((int) $row['attempts']) + 1;
                $error = mb_substr($e::class . ': ' . preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()), 0, 1000, 'UTF-8');
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->connection->executeStatement(
                        'UPDATE mc_notification_outbox
                         SET status = :failed, attempts = :attempts, last_error = :last_error, locked_at = NULL, lock_token = NULL
                         WHERE id = :id AND lock_token = :lock_token',
                        [
                            'failed' => 'failed',
                            'attempts' => $attempts,
                            'last_error' => $error,
                            'id' => $id,
                            'lock_token' => $lockToken,
                        ],
                    );
                    ++$stats['failed'];
                    continue;
                }

                $backoff = min(3600, 30 * (2 ** ($attempts - 1)));
                $availableAt = (new DateTimeImmutable())->add(new DateInterval('PT' . $backoff . 'S'));
                $this->connection->executeStatement(
                    'UPDATE mc_notification_outbox
                     SET status = :pending, attempts = :attempts, last_error = :last_error,
                         available_at = :available_at, locked_at = NULL, lock_token = NULL
                     WHERE id = :id AND lock_token = :lock_token',
                    [
                        'pending' => 'pending',
                        'attempts' => $attempts,
                        'last_error' => $error,
                        'available_at' => $availableAt->format('Y-m-d H:i:s.u'),
                        'id' => $id,
                        'lock_token' => $lockToken,
                    ],
                );
                ++$stats['retried'];
            }
        }

        return $stats;
    }
}
