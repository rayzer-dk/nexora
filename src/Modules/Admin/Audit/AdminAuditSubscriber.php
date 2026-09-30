<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Audit;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Admin\Domain\AdminUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class AdminAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private Connection $db, private Security $security, private PublicIdFactory $publicIds) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -64]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || !str_starts_with($route, 'admin_') || in_array($route, ['admin_login', 'admin_logout', 'admin_login_forgot', 'admin_login_recover'], true)) {
            return;
        }
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $status = $event->getResponse()->getStatusCode();
        if ($status < 200 || $status >= 400) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof AdminUser) {
            return;
        }
        try {
            $entityId = null;
            foreach (['publicId', 'id', 'code', 'type'] as $key) {
                $value = $request->attributes->get($key);
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $entityId = mb_substr((string) $value, 0, 190);
                    break;
                }
            }
            $ip = (string) ($request->getClientIp() ?? '');
            $this->db->insert('mc_audit_log', [
                'public_id' => $this->publicIds->binary(),
                'actor_type' => 'admin',
                'actor_id' => (string) $user->id,
                'action' => mb_substr($route, 0, 190),
                'entity_type' => 'admin_route',
                'entity_id' => $entityId,
                'request_id' => null,
                'ip_hash' => $ip === '' ? null : hash('sha256', $ip, true),
                'metadata' => json_encode([
                    'method' => $request->getMethod(),
                    'status' => $status,
                    'path' => mb_substr($request->getPathInfo(), 0, 500),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
        } catch (\Throwable) {
            // Fail-soft: a logging problem must never invalidate an already completed admin action.
        }
    }
}
