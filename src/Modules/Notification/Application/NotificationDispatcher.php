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

    public function __construct(iterable $senders, private readonly ?\Commerce\Core\Extension\ExtensionServiceRegistry $extensions = null)
    {
        foreach ($senders as $sender) {
            $this->senders[$sender->channel()->value] = $sender;
        }
    }

    public function send(NotificationChannel $channel, NotificationMessage $message, string $recipient): void
    {
        // A module sender (`provider.notification_sender`) takes over its channel, so SMS or a messenger can be served by a gateway of choice.
        $sender = null;
        foreach ($this->extensions?->all('provider.notification_sender') ?? [] as $candidate) {
            if ($candidate instanceof NotificationSenderInterface && $candidate->channel() === $channel) {
                $sender = $candidate;
            }
        }
        $sender ??= $this->senders[$channel->value] ?? null;
        if ($sender === null) {
            throw new LogicException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.c79a4d300e3d'), $channel->value));
        }

        $sender->send($message, $recipient);
    }
}
