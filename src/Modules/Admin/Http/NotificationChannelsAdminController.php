<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\NotificationChannelSettings;
use Commerce\Modules\Notification\Channel\Email\EmailNotificationSender;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Commerce\Modules\SupportChat\Infrastructure\TelegramApiException;
use Commerce\Modules\SupportChat\Infrastructure\TelegramBotClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Where the shop sends e-mail from and which Telegram bot posts order alerts; both can be tried with one click. */
final class NotificationChannelsAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly NotificationChannelSettings $settings,
        private readonly \Commerce\Modules\Notification\Application\TelegramAlertSettings $telegramAlerts,
        private readonly EmailNotificationSender $mailSender,
        private readonly TelegramBotClient $bot,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $envDsn,
        #[Autowire('%commerce.mail.from_address%')] private readonly string $envFrom = '',
    ) {
    }

    #[Route('/admin/commerce/notification-channels', name: 'admin_commerce_notification_channels', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        if ($request->isMethod('POST')) {
            $this->guard($request);
            $in = $request->request;
            try {
                $this->settings->save($storeId, [
                    'smtp_enabled' => $in->getBoolean('smtp_enabled'),
                    'smtp_host' => (string) $in->get('smtp_host', ''),
                    'smtp_port' => (string) $in->get('smtp_port', '587'),
                    'smtp_encryption' => (string) $in->get('smtp_encryption', 'tls'),
                    'smtp_user' => (string) $in->get('smtp_user', ''),
                    'smtp_pass' => (string) $in->get('smtp_pass', ''),
                    'from_address' => (string) $in->get('from_address', ''),
                    'from_name' => (string) $in->get('from_name', ''),
                    'tg_enabled' => $in->getBoolean('tg_enabled'),
                    'tg_token' => (string) $in->get('tg_token', ''),
                    'tg_chat_id' => (string) $in->get('tg_chat_id', ''),
                ]);
                $this->addFlash('success', CanonicalUiText::get('admin.notify_channels.saved'));
            } catch (\InvalidArgumentException) {
                $this->addFlash('error', CanonicalUiText::get('admin.notify_channels.invalid'));
            }

            return $this->redirectToRoute('admin_commerce_notification_channels');
        }
        $s = $this->settings->get($storeId);
        unset($s['smtp_pass'], $s['tg_token']);

        return $this->render('@storefront/admin/commerce/notification_channels.html.twig', [
            's' => $s,
            'env_mail_is_null' => str_starts_with(trim($this->envDsn), 'null:'),
            'env_from' => $this->envFrom,
            'design' => $this->settings->design($storeId),
            'tg_alerts' => $this->telegramAlerts->all(),
        ]);
    }

    #[Route('/admin/commerce/notification-channels/telegram-alerts', name: 'admin_commerce_notification_telegram_alerts', methods: ['POST'])]
    public function telegramAlerts(Request $request): Response
    {
        $this->guard($request);
        $this->telegramAlerts->save($request->request->all());
        $this->addFlash('success', CanonicalUiText::get('admin.notify_channels.alerts_saved'));

        return $this->redirectToRoute('admin_commerce_notification_channels');
    }

    #[Route('/admin/commerce/notification-channels/design', name: 'admin_commerce_notification_design', methods: ['POST'])]
    public function design(Request $request): Response
    {
        $this->guard($request);
        $storeId = $this->contexts->resolve($request)->storeId;
        $in = $request->request;
        if ($in->has('reset')) {
            $this->settings->saveDesign($storeId, []);
        } else {
            $this->settings->saveDesign($storeId, [
                'header_bg' => $in->get('header_bg'), 'header_text' => $in->get('header_text'), 'accent' => $in->get('accent'),
                'page_bg' => $in->get('page_bg'), 'card_bg' => $in->get('card_bg'), 'text' => $in->get('text'), 'footer' => $in->get('footer'),
            ]);
        }
        $this->addFlash('success', CanonicalUiText::get('admin.notify_channels.design_saved'));

        return $this->redirectToRoute('admin_commerce_notification_channels');
    }

    #[Route('/admin/commerce/notification-channels/test', name: 'admin_commerce_notification_channels_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $this->guard($request);
        $storeId = $this->contexts->resolve($request)->storeId;
        $s = $this->settings->get($storeId);
        if ($request->request->get('channel') === 'telegram') {
            if (!$s['has_tg_token'] || $s['tg_chat_id'] === '') {
                $this->addFlash('error', CanonicalUiText::get('admin.notify_channels.tg_incomplete'));

                return $this->redirectToRoute('admin_commerce_notification_channels');
            }
            try {
                $this->bot->call($s['tg_token'], 'sendMessage', ['chat_id' => $s['tg_chat_id'], 'text' => CanonicalUiText::get('admin.notify_channels.test_text')]);
                $this->addFlash('success', CanonicalUiText::get('admin.notify_channels.tg_sent'));
            } catch (TelegramApiException $e) {
                $this->addFlash('error', CanonicalUiText::get('admin.notify_channels.failed', ['error' => $e->getMessage()]));
            }

            return $this->redirectToRoute('admin_commerce_notification_channels');
        }
        $to = trim((string) $request->request->get('to', ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', CanonicalUiText::get('admin.notify_channels.need_email'));

            return $this->redirectToRoute('admin_commerce_notification_channels');
        }
        try {
            // The same sender and template the order e-mails use, so a green test means order mail works too.
            $this->mailSender->send(new NotificationMessage(
                type: 'admin.test',
                subject: CanonicalUiText::get('admin.notify_channels.test_subject'),
                text: CanonicalUiText::get('admin.notify_channels.test_text'),
            ), $to);
            $this->addFlash('success', CanonicalUiText::get('admin.notify_channels.mail_sent', ['to' => $to]));
        } catch (TransportExceptionInterface | \Symfony\Component\Mime\Exception\ExceptionInterface | \LogicException $e) {
            $this->logger->warning('Test e-mail failed', ['exception' => $e]);
            $this->addFlash('error', CanonicalUiText::get('admin.notify_channels.failed', ['error' => mb_substr(strtok($e->getMessage(), "\n") ?: '', 0, 300)]));
        }

        return $this->redirectToRoute('admin_commerce_notification_channels');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_notify_channels', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
