<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\Email;

use Commerce\Modules\Notification\Application\NotificationChannelSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends through the SMTP server saved in the admin; without one it uses the installation's MAILER_DSN.
 * The installation's mailer is lazy: a MAILER_DSN the server cannot use (native://default with a sendmail_path
 * that lacks -t or -bs) fails when a message is sent, where the notification queue records and retries it, instead of
 * breaking every request that builds this service.
 */
final class RuntimeMailer implements MailerInterface
{
    public function __construct(#[Autowire(service: 'mailer.mailer', lazy: true)] private readonly MailerInterface $fallback, private readonly NotificationChannelSettings $settings, private readonly ?EventDispatcherInterface $events = null)
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
