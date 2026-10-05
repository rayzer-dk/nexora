<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Channel\Email;

use Commerce\Modules\Notification\Application\NotificationChannelSettings;
use Commerce\Modules\Notification\Application\NotificationTemplateService;
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
        private readonly NotificationTemplateService $templates,
        private readonly ?NotificationChannelSettings $channels = null,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function send(NotificationMessage $message, string $recipient): void
    {
        $saved = $this->channels?->active();
        $override = $this->templates->resolve($message);
        $subject = $override['subject'] ?? $message->subject;
        $text = $override['body'] ?? $message->text;
        $email = (new TemplatedEmail())
            ->from(new Address(($saved['from_address'] ?? '') !== '' ? $saved['from_address'] : $this->fromAddress, ($saved['from_name'] ?? '') !== '' ? $saved['from_name'] : $this->fromName))
            ->to($recipient)
            ->subject($subject)
            ->htmlTemplate('@storefront/email/' . $message->emailTemplate . '.html.twig')
            ->context($message->context + [
                'notification_subject' => $subject,
                'notification_text' => $text,
                'custom_body' => ($override['html'] ?? false) ? '' : ($override['body'] ?? ''),
                'custom_html' => ($override['html'] ?? false) ? ($override['body'] ?? '') : '',
            ]);

        foreach ((array) ($message->context['attachments'] ?? []) as $file) {
            $path = is_array($file) ? (string) ($file['path'] ?? '') : '';
            if ($path !== '' && is_file($path) && str_contains(str_replace('\\', '/', $path), '/var/order-mail/')) {
                $email->attachFromPath($path, (string) ($file['name'] ?? basename($path)));
            }
        }

        $this->mailer->send($email);
    }
}
