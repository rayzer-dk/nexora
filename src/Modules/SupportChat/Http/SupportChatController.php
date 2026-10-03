<?php

declare(strict_types=1);

namespace Commerce\Modules\SupportChat\Http;

use Commerce\Modules\Security\Spam\PublicFormProtection;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Commerce\Modules\SupportChat\Application\SupportChatService;
use Commerce\Modules\SupportChat\Application\SupportChatSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The website side of the support chat: a visitor sends a message and polls for the answers written in Telegram. */
final class SupportChatController extends AbstractController
{
    private const COOKIE = 'mc_support';

    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly SupportChatSettings $settings,
        private readonly SupportChatService $chat,
        private readonly PublicFormProtection $protection,
    ) {
    }

    #[Route('/support/chat/send', name: 'storefront_support_chat_send', methods: ['POST'], priority: 950)]
    public function send(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $s = $this->settings->get($storeId);
        if (!$this->settings->isReady($storeId) || !$s['site_chat_enabled']) {
            return $this->reply(['ok' => false, 'code' => 'unavailable'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid('support_chat', (string) $request->request->get('_token'))) {
            return $this->reply(['ok' => false, 'code' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        $text = trim((string) $request->request->get('message', ''));
        if ($text === '' || mb_strlen($text) > 2000) {
            return $this->reply(['ok' => false, 'code' => 'invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $token = (string) $request->cookies->get(self::COOKIE, '');
        $thread = $token !== '' ? $this->chat->threadByToken($storeId, $token) : null;
        $isNew = $thread === null;
        if ($isNew) {
            if (!$this->protection->allow($request, 'support_chat')) {
                return $this->reply(['ok' => false, 'code' => 'spam'], Response::HTTP_TOO_MANY_REQUESTS);
            }
            $thread = $this->chat->createWebThread(
                $storeId,
                (string) $request->request->get('name', ''),
                (string) $request->request->get('contact', ''),
                null,
                (string) $request->request->get('page', ''),
            );
        } elseif ($this->chat->recentVisitorMessages((int) $thread['id']) >= 20) {
            return $this->reply(['ok' => false, 'code' => 'rate'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $result = $this->chat->postFromVisitor($storeId, $thread, $text);
        $response = $this->reply(['ok' => true, 'id' => $result['id'], 'delivered' => $result['delivered']]);
        if ($isNew) {
            $response->headers->setCookie(Cookie::create(self::COOKIE, (string) $thread['public_token'], time() + 90 * 86400, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    #[Route('/support/chat/poll', name: 'storefront_support_chat_poll', methods: ['GET'], priority: 950)]
    public function poll(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $token = (string) $request->cookies->get(self::COOKIE, '');
        $thread = $token !== '' && $this->settings->isReady($storeId) ? $this->chat->threadByToken($storeId, $token) : null;
        if ($thread === null) {
            return $this->reply(['ok' => true, 'messages' => [], 'started' => false]);
        }

        return $this->reply(['ok' => true, 'started' => true, 'messages' => $this->chat->messages((int) $thread['id'], max(0, (int) $request->query->get('after', 0)))]);
    }

    /** @param array<string,mixed> $data */
    private function reply(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }
}
