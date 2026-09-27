<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Core\Runtime\DeferredWorkSignal;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class NotificationOutbox
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PublicIdFactory $publicIdFactory,
        private readonly DeferredWorkSignal $deferredWork,
    ) {
    }

    public function enqueue(
        NotificationChannel $channel,
        NotificationMessage $message,
        string $recipient,
        ?DateTimeImmutable $availableAt = null,
        ?string $dedupeKey = null,
    ): void {
        $availableAt ??= new DateTimeImmutable();
        $dedupeKey = $dedupeKey !== null ? trim($dedupeKey) : null;
        if ($dedupeKey !== null && ($dedupeKey === '' || strlen($dedupeKey) > 190)) {
            throw new \InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.ba4f4bc96105'));
        }

        try {
            $this->connection->insert('mc_notification_outbox', [
                'public_id' => $this->publicIdFactory->binary(),
                'dedupe_key' => $dedupeKey,
                'channel' => $channel->value,
                'notification_type' => $message->type,
                'recipient' => $recipient,
                'payload' => json_encode([
                    'subject' => $message->subject,
                    'text' => $message->text,
                    'context' => $message->context,
                    'email_template' => $message->emailTemplate,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'available_at' => $availableAt->format('Y-m-d H:i:s.u'),
                'attempts' => 0,
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($dedupeKey === null) {
                throw $e;
            }
            // Re-delivery of the same asynchronous domain event is idempotent.
        }
        $this->deferredWork->mark();
    }
}
