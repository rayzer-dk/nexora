<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\Email;

use Commerce\Modules\Notification\Application\NotificationChannelSettings;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\RawMessage;

/** Sends through the SMTP server saved in the admin; without one it uses the installation's MAILER_DSN. */
final class RuntimeMailer implements MailerInterface
{
    public function __construct(private readonly MailerInterface $fallback, private readonly NotificationChannelSettings $settings, private readonly ?EventDispatcherInterface $events = null)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $dsn = $this->settings->smtpDsn($this->settings->active());
        if ($dsn === null) {
            $this->fallback->send($message, $envelope);

            return;
        }
        // The transport dispatches the message event, and the framework's listener renders the Twig template there.
        (new Mailer(Transport::fromDsn($dsn, $this->events)))->send($message, $envelope);
    }
}
