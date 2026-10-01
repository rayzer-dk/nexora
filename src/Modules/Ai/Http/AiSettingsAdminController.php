<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Ai\Application\AiModelCatalog;
use Commerce\Modules\Ai\Application\AiModelCatalogException;
use Commerce\Modules\Ai\Application\AiSettings;
use Commerce\Modules\Ai\Application\AiTaskService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AiSettingsAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly AiSettings $settings, private readonly AiTaskService $tasks, private readonly AiModelCatalog $models)
    {
    }

    #[Route('/admin/system/ai', name: 'admin_system_ai', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $providers = $this->settings->providers($storeId);
        foreach ($providers as $code => &$p) {
            $p['label'] = AiSettings::PROVIDERS[$code];
            unset($p['key']); // the key never reaches the page
            $p['models'] = $this->models->options($storeId, $code, $p['model']);
        }
        unset($p);

        return $this->render('@storefront/admin/system/ai.html.twig', [
            'providers' => $providers,
            'limit' => $this->settings->dailyLimit($storeId),
            'used' => $this->settings->usedToday($storeId),
            'usage' => $this->settings->recentUsage($storeId),
            'tasks' => AiTaskService::tasks(),
        ]);
    }

    #[Route('/admin/system/ai/save', name: 'admin_system_ai_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $this->guard($request);
        $storeId = $this->contexts->resolve($request)->storeId;
        $r = $request->request;
        try {
            if ($r->has('daily_limit')) {
                $this->settings->saveDailyLimit($storeId, $r->getInt('daily_limit'));
            } else {
                $this->settings->saveProvider($storeId, (string) $r->get('provider', ''), $r->has('enabled'), trim((string) $r->get('model_custom', '')) !== '' ? (string) $r->get('model_custom', '') : (string) $r->get('model', ''), (string) $r->get('api_key', ''));
            }
            $this->addFlash('success', CanonicalUiText::get('admin.ai.saved'));
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', CanonicalUiText::get('admin.ai.error_settings'));
        }

        return $this->redirectToRoute('admin_system_ai');
    }

    #[Route('/admin/system/ai/key-delete', name: 'admin_system_ai_key_delete', methods: ['POST'])]
    public function deleteKey(Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->settings->removeKey($this->contexts->resolve($request)->storeId, (string) $request->request->get('provider', ''));
        $this->addFlash('success', CanonicalUiText::get('admin.ai.key_removed'));

        return $this->redirectToRoute('admin_system_ai');
    }

    #[Route('/admin/system/ai/models-refresh', name: 'admin_system_ai_models_refresh', methods: ['POST'])]
    public function refreshModels(Request $request): RedirectResponse
    {
        $this->guard($request);
        $provider = (string) $request->request->get('provider', '');
        try {
            $count = count($this->models->refresh($this->contexts->resolve($request)->storeId, $provider));
            $this->addFlash('success', CanonicalUiText::get('admin.ai.models_refreshed', ['count' => $count, 'provider' => AiSettings::PROVIDERS[$provider] ?? $provider]));
        } catch (AiModelCatalogException $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.ai.models_error.' . $e->getMessage(), ['provider' => AiSettings::PROVIDERS[$provider] ?? $provider]));
        }

        return $this->redirectToRoute('admin_system_ai');
    }

    #[Route('/admin/system/ai/test', name: 'admin_system_ai_test', methods: ['POST'])]
    public function test(Request $request): RedirectResponse
    {
        $this->guard($request);
        try {
            $this->tasks->ping($this->contexts->resolve($request)->storeId, (string) $request->request->get('provider', ''));
            $this->addFlash('success', CanonicalUiText::get('admin.ai.test_ok'));
        } catch (\Throwable) {
            $this->addFlash('error', CanonicalUiText::get('admin.ai.test_failed'));
        }

        return $this->redirectToRoute('admin_system_ai');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_ai_settings', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
