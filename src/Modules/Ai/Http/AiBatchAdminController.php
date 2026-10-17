<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Ai\Application\AiBatchService;
use Commerce\Modules\Ai\Application\AiSettings;
use Commerce\Modules\Catalog\Application\CatalogTranslationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The "AI batch translation" page: start a batch, watch it per language, process now, pause, resume, cancel, retry errors. */
final class AiBatchAdminController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly AiBatchService $batches,
        private readonly AiSettings $settings,
        private readonly CatalogTranslationService $catalog,
        private readonly AdminContextResolver $contexts,
    ) {
    }

    #[Route('/admin/catalog/ai-batch', name: 'admin_ai_batch', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return $this->json(['ok' => true] + $this->state($storeId));
        }
        $locales = $this->catalog->locales($storeId);

        return $this->render('@storefront/admin/ai/batch.html.twig', [
            'locales' => array_values(array_filter($locales, static fn (array $l): bool => !$l['is_default'])),
            'default_locale' => array_values(array_filter($locales, static fn (array $l): bool => $l['is_default']))[0] ?? null,
            'providers' => $this->batches->providers($storeId),
            'entity_types' => AiBatchService::ENTITY_TYPES,
        ] + $this->state($storeId));
    }

    #[Route('/admin/catalog/ai-batch/start', name: 'admin_ai_batch_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->guard($request);
        $storeId = $this->contexts->resolve($request)->storeId;
        $r = $request->request;
        try {
            $this->batches->start($storeId, $this->getUser()?->getUserIdentifier() ?? 'admin', [
                'kind' => 'translate',
                'entity_types' => array_map('strval', $r->all('entity_types')),
                'locales' => array_map('strval', $r->all('locales')),
                'mode' => (string) $r->get('mode', 'missing'),
                'provider' => (string) $r->get('provider', ''),
            ]);
            $this->addFlash('success', CanonicalUiText::get('admin.ai.batch.started'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_ai_batch');
    }

    /** One round of work right now (the scheduler does the same every 5 minutes); answers the new progress. */
    #[Route('/admin/catalog/ai-batch/run', name: 'admin_ai_batch_run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_ai_batch', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => CanonicalUiText::get('common.security.invalid_csrf')], 403);
        }
        $storeId = $this->contexts->resolve($request)->storeId;
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $round = $this->batches->process(10, 25.0, $storeId);

        return $this->json(['ok' => true, 'round' => $round] + $this->state($storeId));
    }

    #[Route('/admin/catalog/ai-batch/{publicId}/{action}', name: 'admin_ai_batch_action', methods: ['POST'], requirements: ['publicId' => self::UUID, 'action' => 'pause|resume|cancel|retry'])]
    public function action(string $publicId, string $action, Request $request): Response
    {
        $this->guard($request);
        $storeId = $this->contexts->resolve($request)->storeId;
        match ($action) {
            'pause' => $this->batches->pause($publicId, $storeId),
            'resume' => $this->batches->resume($publicId, $storeId),
            'cancel' => $this->batches->cancel($publicId, $storeId),
            default => $this->batches->retryFailed($publicId, $storeId),
        };
        $this->addFlash('success', CanonicalUiText::get('admin.ai.batch.done_' . $action));

        return $this->redirectToRoute('admin_ai_batch');
    }

    /** @return array{batches:list<array<string,mixed>>,limit:int,used:int} */
    private function state(int $storeId): array
    {
        return ['batches' => $this->batches->progress($storeId), 'limit' => $this->settings->dailyLimit($storeId), 'used' => $this->settings->usedToday($storeId)];
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_ai_batch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
