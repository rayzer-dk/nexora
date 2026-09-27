<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\Email;

use Commerce\Modules\Notification\Contract\NotificationSenderInterface;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class EmailNotificationSender implements NotificationSenderInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function send(NotificationMessage $message, string $recipient): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($recipient)
            ->subject($message->subject)
            ->htmlTemplate('@storefront/email/' . $message->emailTemplate . '.html.twig')
            ->context($message->context + [
                'notification_subject' => $message->subject,
                'notification_text' => $message->text,
            ]);

        $this->mailer->send($email);
    }
}
