<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\SupportChat\Application\SupportChatService;
use Commerce\Modules\SupportChat\Application\SupportChatSettings;
use Commerce\Modules\SupportChat\Infrastructure\TelegramApiException;
use Commerce\Modules\SupportChat\Infrastructure\TelegramBotClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Telegram support chat: bot token, staff group, webhook and a list of the latest conversations. */
final class SupportChatAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly SupportChatSettings $settings,
        private readonly SupportChatService $chat,
        private readonly TelegramBotClient $bot,
        #[Autowire('%commerce.app_public_url%')] private readonly string $publicUrl = '',
    ) {
    }

    #[Route('/admin/appearance/support-chat', name: 'admin_appearance_support_chat', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            $this->guard($request);
            try {
                $this->settings->save($context->storeId, [
                    'enabled' => $request->request->getBoolean('enabled'),
                    'site_chat_enabled' => $request->request->getBoolean('site_chat_enabled'),
                    'direct_enabled' => $request->request->getBoolean('direct_enabled'),
                    'bot_token' => (string) $request->request->get('bot_token', ''),
                    'group_chat_id' => (string) ($request->request->get('group_chat_pick', '') ?: $request->request->get('group_chat_id', '')),
                    'welcome_text' => (string) $request->request->get('welcome_text', ''),
                    'offline_text' => (string) $request->request->get('offline_text', ''),
                    'load_delay_seconds' => (int) $request->request->get('load_delay_seconds', 3),
                ]);
                $this->addFlash('success', CanonicalUiText::get('admin.support_chat.saved'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', CanonicalUiText::get('admin.support_chat.invalid_input'));
            }

            return $this->redirectToRoute('admin_appearance_support_chat');
        }
        $s = $this->settings->get($context->storeId);
        unset($s['bot_token']);

        return $this->render('@storefront/admin/appearance/support_chat.html.twig', [
            's' => $s,
            'threads' => $this->safeThreads($context->storeId),
            'webhook_url' => $this->webhookUrl($request, $s['webhook_secret']),
        ]);
    }

    #[Route('/admin/appearance/support-chat/connect', name: 'admin_appearance_support_chat_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $this->guard($request);
        $storeId = $this->contexts->resolve($request)->storeId;
        $s = $this->settings->get($storeId);
        if (!$s['has_token'] || $s['webhook_secret'] === '') {
            $this->addFlash('error', CanonicalUiText::get('admin.support_chat.need_token'));

            return $this->redirectToRoute('admin_appearance_support_chat');
        }
        $url = $this->webhookUrl($request, $s['webhook_secret']);
        if (!str_starts_with($url, 'https://')) {
            $this->addFlash('error', CanonicalUiText::get('admin.support_chat.need_https'));

            return $this->redirectToRoute('admin_appearance_support_chat');
        }
        try {
            $me = $this->bot->call($s['bot_token'], 'getMe');
            $this->settings->setBotUsername($storeId, (string) ($me['username'] ?? ''));
            $this->bot->call($s['bot_token'], 'setWebhook', ['url' => $url, 'secret_token' => $s['webhook_secret'], 'allowed_updates' => ['message', 'my_chat_member'], 'drop_pending_updates' => true]);
            $this->addFlash('success', CanonicalUiText::get('admin.support_chat.connected', ['bot' => '@' . (string) ($me['username'] ?? '')]));
        } catch (TelegramApiException $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.support_chat.telegram_error', ['error' => $e->getMessage()]));
        }

        return $this->redirectToRoute('admin_appearance_support_chat');
    }

    #[Route('/admin/appearance/support-chat/check', name: 'admin_appearance_support_chat_check', methods: ['POST'])]
    public function check(Request $request): Response
    {
        $this->guard($request);
        $s = $this->settings->get($this->contexts->resolve($request)->storeId);
        if (!$s['has_token']) {
            $this->addFlash('error', CanonicalUiText::get('admin.support_chat.need_token'));

            return $this->redirectToRoute('admin_appearance_support_chat');
        }
        $problems = [];
        try {
            $me = $this->bot->call($s['bot_token'], 'getMe');
            $hook = $this->bot->call($s['bot_token'], 'getWebhookInfo');
            if (!str_ends_with((string) ($hook['url'] ?? ''), '/support/telegram/webhook/' . $s['webhook_secret'])) {
                $problems[] = CanonicalUiText::get('admin.support_chat.check_webhook');
            } elseif ((string) ($hook['last_error_message'] ?? '') !== '') {
                $problems[] = CanonicalUiText::get('admin.support_chat.check_webhook_error', ['error' => (string) $hook['last_error_message']]);
            }
            if ($s['group_chat_id'] === '') {
                $problems[] = CanonicalUiText::get('admin.support_chat.check_group_missing');
            } else {
                $chat = $this->bot->call($s['bot_token'], 'getChat', ['chat_id' => $s['group_chat_id']]);
                if (empty($chat['is_forum'])) {
                    $problems[] = CanonicalUiText::get('admin.support_chat.check_topics');
                }
                $member = $this->bot->call($s['bot_token'], 'getChatMember', ['chat_id' => $s['group_chat_id'], 'user_id' => (int) ($me['id'] ?? 0)]);
                $isAdmin = in_array((string) ($member['status'] ?? ''), ['administrator', 'creator'], true);
                if (!$isAdmin || (($member['can_manage_topics'] ?? true) === false)) {
                    $problems[] = CanonicalUiText::get('admin.support_chat.check_admin');
                }
            }
        } catch (TelegramApiException $e) {
            $problems[] = CanonicalUiText::get('admin.support_chat.telegram_error', ['error' => $e->getMessage()]);
        }
        if ($problems === []) {
            $this->addFlash('success', CanonicalUiText::get('admin.support_chat.check_ok'));
        } else {
            foreach ($problems as $problem) {
                $this->addFlash('error', $problem);
            }
        }

        return $this->redirectToRoute('admin_appearance_support_chat');
    }

    #[Route('/admin/appearance/support-chat/disconnect', name: 'admin_appearance_support_chat_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): Response
    {
        $this->guard($request);
        $s = $this->settings->get($this->contexts->resolve($request)->storeId);
        if ($s['has_token']) {
            try {
                $this->bot->call($s['bot_token'], 'deleteWebhook', ['drop_pending_updates' => true]);
            } catch (\Throwable) {
            }
        }
        $this->addFlash('success', CanonicalUiText::get('admin.support_chat.disconnected'));

        return $this->redirectToRoute('admin_appearance_support_chat');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_support_chat', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }

    private function webhookUrl(Request $request, string $secret): string
    {
        $base = rtrim($this->publicUrl !== '' && str_starts_with($this->publicUrl, 'http') ? $this->publicUrl : $request->getSchemeAndHttpHost(), '/');

        return $secret === '' ? '' : $base . '/support/telegram/webhook/' . $secret;
    }

    /** @return list<array<string,mixed>> */
    private function safeThreads(int $storeId): array
    {
        try {
            return $this->chat->recentThreads($storeId);
        } catch (\Throwable) {
            return [];
        }
    }
}
