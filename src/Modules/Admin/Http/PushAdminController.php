<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\NotificationOutbox;
use Commerce\Modules\Notification\Channel\WebPush\WebPushNotificationSender;
use Commerce\Modules\Notification\Domain\NotificationChannel;
use Commerce\Modules\Notification\Domain\NotificationMessage;
use Commerce\Modules\Push\Application\PushSettings;
use Commerce\Modules\Push\Application\PushSubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PushAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly PushSettings $settings,
        private readonly PushSubscriptionService $subscriptions,
        private readonly WebPushNotificationSender $sender,
        private readonly NotificationOutbox $outbox,
    ) {
    }

    #[Route('/admin/system/push', name: 'admin_system_push', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            $this->guard($request);
            try {
                $this->settings->save($context->storeId, $request->request->getBoolean('enabled'), (string) $request->request->get('subject', ''));
                $this->addFlash('success', CanonicalUiText::get('admin.push.saved'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('admin_system_push');
        }

        return $this->render('@storefront/admin/system/push.html.twig', [
            's' => $this->settings->get($context->storeId),
            'counts' => $this->subscriptions->counts($context->storeId),
        ]);
    }

    #[Route('/admin/system/push/keys', name: 'admin_system_push_keys', methods: ['POST'])]
    public function keys(Request $request): RedirectResponse
    {
        $this->guard($request);
        $context = $this->contexts->resolve($request);
        $this->settings->regenerate($context->storeId);
        $this->addFlash('success', CanonicalUiText::get('admin.push.keys_regenerated'));

        return $this->redirectToRoute('admin_system_push');
    }

    #[Route('/admin/system/push/test', name: 'admin_system_push_test', methods: ['POST'])]
    public function test(Request $request): RedirectResponse
    {
        $this->guard($request);
        $context = $this->contexts->resolve($request);
        $stats = $this->sender->deliver($context->storeId, 'admin', CanonicalUiText::get('admin.push.test_title'), CanonicalUiText::get('admin.push.test_body'), '/admin');
        $this->addFlash($stats['sent'] > 0 ? 'success' : 'error', CanonicalUiText::get('admin.push.test_result', ['sent' => $stats['sent'], 'failed' => $stats['failed'] + $stats['removed']]));

        return $this->redirectToRoute('admin_system_push');
    }

    #[Route('/admin/system/push/broadcast', name: 'admin_system_push_broadcast', methods: ['POST'])]
    public function broadcast(Request $request): RedirectResponse
    {
        $this->guard($request);
        $context = $this->contexts->resolve($request);
        $title = trim(mb_substr((string) $request->request->get('title', ''), 0, 80));
        $body = trim(mb_substr((string) $request->request->get('body', ''), 0, 240));
        $url = trim((string) $request->request->get('url', '/'));
        if ($title === '' || $body === '' || !((str_starts_with($url, '/') && !str_starts_with($url, '//')) || str_starts_with($url, 'https://'))) {
            $this->addFlash('error', CanonicalUiText::get('admin.push.error_broadcast'));

            return $this->redirectToRoute('admin_system_push');
        }
        $this->outbox->enqueue(NotificationChannel::WebPush, new NotificationMessage('push.broadcast', $title, $body, ['url' => $url], 'generic'), 'storefront:' . $context->storeId, null, 'push-broadcast:' . bin2hex(random_bytes(8)));
        $this->addFlash('success', CanonicalUiText::get('admin.push.broadcast_queued', ['count' => $this->subscriptions->counts($context->storeId)['storefront']]));

        return $this->redirectToRoute('admin_system_push');
    }

    #[Route('/admin/system/push/subscribe', name: 'admin_system_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_push', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['ok' => false], 403);
        }
        $context = $this->contexts->resolve($request);
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_array($data['keys'] ?? null) || !$this->settings->get($context->storeId)['enabled']) {
            return new JsonResponse(['ok' => false], 400);
        }
        try {
            $this->subscriptions->subscribe($context->storeId, 'admin', (string) ($data['endpoint'] ?? ''), (string) ($data['keys']['p256dh'] ?? ''), (string) ($data['keys']['auth'] ?? ''), $context->locale);
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false], 422);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_push', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
