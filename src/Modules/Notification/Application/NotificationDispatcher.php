<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Application;

use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use LogicException;

final class NotificationDispatcher
{
    private array $senders = [];

    public function __construct(iterable $senders)
    {
        foreach ($senders as $sender) {
            $this->senders[$sender->channel()->value] = $sender;
        }
    }

    public function send(NotificationChannel $channel, NotificationMessage $message, string $recipient): void
    {
        $sender = $this->senders[$channel->value] ?? null;
        if ($sender === null) {
            throw new LogicException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.c79a4d300e3d'), $channel->value));
        }

        $sender->send($message, $recipient);
    }
}
