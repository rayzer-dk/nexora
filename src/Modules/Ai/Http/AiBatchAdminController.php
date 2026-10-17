<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Admin\Undo\AdminUndoService;
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
    /** Session key of the products ticked in the product list for an AI action. */
    public const SELECTION = 'ai_bulk_selection';
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly AiBatchService $batches,
        private readonly AiSettings $settings,
        private readonly CatalogTranslationService $catalog,
        private readonly AdminContextResolver $contexts,
        private readonly AdminUndoService $undo,
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
        $selection = $request->query->getBoolean('selection') ? $this->selection($request) : null;
        $providers = $this->batches->providers($storeId);

        return $this->render('@storefront/admin/ai/batch.html.twig', [
            'selection' => $selection === null ? null : ['task' => $selection['task'], 'count' => count($selection['ids'])],
            'selection_providers' => $selection !== null && $selection['task'] !== 'translate' ? array_values(array_filter($providers, static fn (array $p): bool => !$p['translation_only'])) : $providers,
            'review_count' => $this->batches->reviewCount($storeId),
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
        $options = [
            'kind' => 'translate',
            'entity_types' => array_map('strval', $r->all('entity_types')),
            'locales' => array_map('strval', $r->all('locales')),
            'mode' => (string) $r->get('mode', 'missing'),
            'provider' => (string) $r->get('provider', ''),
        ];
        $selected = null;
        if ($r->getBoolean('selection')) {
            $selected = $this->selection($request);
            if ($selected === null) {
                $this->addFlash('error', CanonicalUiText::get('admin.ai.bulk.expired'));

                return $this->redirectToRoute('admin_catalog_products');
            }
            $options['product_ids'] = $selected['ids'];
            if ($selected['task'] !== 'translate') {
                $options['kind'] = 'generate';
                $options['task'] = $selected['task'];
            }
        }
        try {
            $this->batches->start($storeId, $this->getUser()?->getUserIdentifier() ?? 'admin', $options);
            $this->addFlash('success', CanonicalUiText::get($options['kind'] === 'generate' ? 'admin.ai.bulk.started_generate' : 'admin.ai.batch.started'));
            if ($selected !== null) {
                $request->getSession()->remove(self::SELECTION);
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            if ($selected !== null) {
                return $this->redirectToRoute('admin_ai_batch', ['selection' => 1]);
            }
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

    /** Texts the AI wrote, before and after, waiting for the owner. */
    #[Route('/admin/catalog/ai-batch/review', name: 'admin_ai_batch_review', methods: ['GET'])]
    public function review(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $page = max(1, $request->query->getInt('page', 1));
        $count = $this->batches->reviewCount($storeId);

        return $this->render('@storefront/admin/ai/review.html.twig', ['items' => $this->batches->review($storeId, $page), 'count' => $count, 'page' => $page, 'pages' => max(1, (int) ceil($count / 20))]);
    }

    #[Route('/admin/catalog/ai-batch/review/apply', name: 'admin_ai_batch_review_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $this->guard($request);
        $ctx = $this->contexts->resolve($request);
        $ids = $request->request->getBoolean('all') ? $this->batches->reviewJobIds($ctx->storeId) : array_map('intval', $request->request->all('ids'));
        $applied = $this->batches->applyJobs($ctx->storeId, $ids);
        $user = $this->getUser();
        if ($applied === []) {
            $this->addFlash('error', CanonicalUiText::get('admin.ai.review.nothing'));
        } else {
            if ($user instanceof AdminUser) {
                $this->undo->remember($ctx->storeId, $user->id, 'ai_review', CanonicalUiText::get('admin.undo.ai_review', ['count' => count($applied)]), ['jobs' => $applied]);
            }
            $this->addFlash('success', CanonicalUiText::get('admin.ai.review.applied', ['count' => count($applied)]));
        }

        return $this->redirectToRoute('admin_ai_batch_review');
    }

    #[Route('/admin/catalog/ai-batch/review/reject', name: 'admin_ai_batch_review_reject', methods: ['POST'])]
    public function reject(Request $request): Response
    {
        $this->guard($request);
        $storeId = $this->contexts->resolve($request)->storeId;
        $ids = $request->request->getBoolean('all') ? $this->batches->reviewJobIds($storeId) : array_map('intval', $request->request->all('ids'));
        $this->addFlash('success', CanonicalUiText::get('admin.ai.review.rejected', ['count' => $this->batches->reject($storeId, $ids)]));

        return $this->redirectToRoute('admin_ai_batch_review');
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

    /** @return array{task:string,ids:list<string>}|null the products ticked in the list, kept in the session by the bulk action */
    private function selection(Request $request): ?array
    {
        $data = $request->getSession()->get(self::SELECTION);
        if (!is_array($data) || !in_array($data['task'] ?? '', ['product_draft', 'seo_meta', 'translate'], true) || !is_array($data['ids'] ?? null) || $data['ids'] === [] || (int) ($data['at'] ?? 0) < time() - 3600) {
            return null;
        }

        return ['task' => (string) $data['task'], 'ids' => array_values(array_map('strval', $data['ids']))];
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_ai_batch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
