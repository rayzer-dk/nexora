<?php

declare(strict_types=1);

namespace Example\OrderHook;

use Commerce\Core\Event\StoredDomainEvent;
use Commerce\Core\Extension\TrustedExtensionContext;
use Commerce\Core\Extension\TrustedExtensionEntrypointInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Entrypoint implements TrustedExtensionEntrypointInterface
{
    public function boot(TrustedExtensionContext $context): void
    {
        $log = OrderLog::forInstallPath($context->installPath, $context->code);

        // 1. An event declared in manifest.json "events". The outbox worker calls it after the order is committed.
        $context->on('commerce.order.placed', static function (StoredDomainEvent $event) use ($log): void {
            $log->append($event->eventId, [
                'order_number' => (string) ($event->payload['order_number'] ?? ''),
                'currency' => (string) ($event->payload['currency'] ?? ''),
                'total_minor' => (int) ($event->payload['total_minor'] ?? 0),
            ]);
        });

        // 2. A task declared in manifest.json "scheduled_tasks"; it shows up in Admin -> System -> Cron.
        $context->scheduledTask('digest', static fn (): string => $log->digest());

        // 3. An admin page: the path and the permission are declared in manifest.json "routes" / "permissions".
        $context->route('extension.example_order_hook.log', static function (Request $request, array $route) use ($log): Response {
            $rows = '';
            foreach (array_reverse($log->tail(50)) as $row) {
                $rows .= sprintf(
                    '<tr><td>%s</td><td>%s</td><td>%s %.2f</td></tr>',
                    htmlspecialchars((string) ($row['logged_at'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) ($row['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) ($row['currency'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    ((int) ($row['total_minor'] ?? 0)) / 100,
                );
            }
            $body = '<!doctype html><html lang="uk"><meta charset="utf-8"><title>Журнал замовлень</title>'
                . '<h1>Журнал замовлень (модуль example.order_hook)</h1>'
                . '<p><a href="/admin">&larr; Адмінка</a></p>'
                . '<table border="1" cellpadding="6"><tr><th>Час (UTC)</th><th>Замовлення</th><th>Сума</th></tr>' . ($rows !== '' ? $rows : '<tr><td colspan="3">Поки порожньо</td></tr>') . '</table>';

            return new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
    }
}
