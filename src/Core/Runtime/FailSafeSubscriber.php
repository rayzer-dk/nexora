<?php

declare(strict_types=1);

namespace Commerce\Core\Runtime;

use Commerce\Core\Id\PublicIdFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class FailSafeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private StorefrontLastKnownGoodCache $cache,
        private LoggerInterface $logger,
        private Connection $connection,
        private PublicIdFactory $publicIds,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -120],
            KernelEvents::EXCEPTION => ['onException', -250],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        try {
            $this->cache->capture($event->getRequest(), $event->getResponse());
        } catch (Throwable $e) {
            $this->logger->warning('Fail-safe snapshot write failed.', ['exception' => $e]);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }
        $throwable = $event->getThrowable();
        if ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500) {
            return;
        }

        $request = $event->getRequest();
        $requestId = bin2hex(random_bytes(12));
        $area = $this->area($request);
        $fallback = null;

        $this->logger->critical('Unhandled runtime exception intercepted by fail-safe layer.', [
            'request_id' => $requestId,
            'route' => $request->attributes->get('_route'),
            'path' => $request->getPathInfo(),
            'exception' => $throwable,
        ]);

        if ($area === 'storefront') {
            try {
                $cached = $this->cache->restore($request);
            } catch (Throwable) {
                $cached = null;
            }
            if ($cached instanceof Response) {
                $cached->headers->set('X-Request-Id', $requestId);
                $event->setResponse($cached);
                $fallback = 'last-known-good';
                $this->recordIncident($request, $throwable, $requestId, $area, $fallback);
                return;
            }
        }

        $response = $this->fallbackResponse($request, $requestId, $area);
        $event->setResponse($response);
        $this->recordIncident($request, $throwable, $requestId, $area, $area === 'api' ? 'json-safe-error' : 'static-safe-error');
    }

    private function fallbackResponse(Request $request, string $requestId, string $area): Response
    {
        if ($area === 'api') {
            return new JsonResponse([
                'error' => 'temporary_unavailable',
                'message' => \Commerce\Core\I18n\CanonicalUiText::get('php.core.runtime.failsafesubscriber.servis_tymchasovo_nedostupnyi_povtorit_zapyt_piznish'),
                'request_id' => $requestId,
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '15', 'X-Request-Id' => $requestId]);
        }

        $admin = $area === 'admin';
        $title = $admin ? \Commerce\Core\I18n\CanonicalUiText::get('php.core.runtime.failsafesubscriber.diiu_ne_vykonano') : \Commerce\Core\I18n\CanonicalUiText::get('php.core.runtime.failsafesubscriber.storinka_tymchasovo_pratsiuie_v_rezervnomu_rezhymi');
        $text = $admin
            ? \Commerce\Core\I18n\CanonicalUiText::get('php.core.runtime.failsafesubscriber.potochna_operatsiia_zavershylasia_pomylkoiu_inshi_ro')
            : \Commerce\Core\I18n\CanonicalUiText::get('php.core.runtime.failsafesubscriber.pid_chas_formuvannia_tsiiei_storinky_vynykla_pomylka');
        $back = $admin ? '/admin' : '/';
        $html = '<!doctype html><html lang="uk-UA"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title><style>body{margin:0;background:#f5f7fb;color:#172033;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{max-width:760px;margin:10vh auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ef;border-top:4px solid #0057d9;border-radius:18px;padding:28px;box-shadow:0 20px 60px rgba(15,23,42,.08)}h1{font-size:28px;margin:0 0 12px}p{line-height:1.65;color:#526176}.id{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#f2f4f7;border-radius:7px;padding:3px 6px;color:#172033}.button{display:inline-flex;margin-top:8px;background:#0b63f6;color:#fff;text-decoration:none;border-radius:10px;padding:11px 15px;font-weight:700}</style></head><body><main class="wrap"><section class="card"><h1>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</h1><p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<span class="id">' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</span>.</p><a class="button" href="' . $back . \Commerce\Core\I18n\CanonicalUiText::get('php.core.runtime.failsafesubscriber.povernutysia_a_section_main_body_html');

        return new Response($html, Response::HTTP_SERVICE_UNAVAILABLE, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Retry-After' => '15',
            'X-Request-Id' => $requestId,
            'X-Commerce-Fallback' => 'safe-error',
        ]);
    }

    private function area(Request $request): string
    {
        $path = $request->getPathInfo();
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return str_contains($path, '/api/') ? 'api' : 'admin';
        }
        if ($path === '/api' || str_starts_with($path, '/api/') || $request->getPreferredFormat() === 'json') {
            return 'api';
        }
        return 'storefront';
    }

    private function recordIncident(Request $request, Throwable $throwable, string $requestId, string $area, string $fallback): void
    {
        try {
            if (!$this->connection->createSchemaManager()->tablesExist(['mc_runtime_incident'])) {
                return;
            }
            $summary = preg_replace('/[\r\n\t]+/', ' ', $throwable->getMessage()) ?: 'Runtime error';
            $summary = mb_substr($summary, 0, 1000, 'UTF-8');
            $this->connection->insert('mc_runtime_incident', [
                'public_id' => $this->publicIds->binary(),
                'request_id' => $requestId,
                'area' => $area,
                'route_name' => ($route = $request->attributes->get('_route')) !== null ? mb_substr((string) $route, 0, 190, 'UTF-8') : null,
                'path_hash' => hash('sha256', $request->getPathInfo(), true),
                'severity' => 'error',
                'fallback_mode' => $fallback,
                'error_class' => mb_substr($throwable::class, 0, 255, 'UTF-8'),
                'error_summary' => $summary,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);
        } catch (Throwable) {
            // Incident recording must never become a second failure path.
        }
    }
}
