<?php

declare(strict_types=1);

namespace Commerce\Modules\Director\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Director\Application\DirectorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → Director: today's three proposed actions, the switch and the size of the sale price. */
final class DirectorAdminController extends AbstractController
{
    public function __construct(private readonly DirectorService $director, private readonly AdminContextResolver $contexts)
    {
    }

    #[Route('/admin/director', name: 'admin_director', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);

        return $this->render('@storefront/admin/director/index.html.twig', ['settings' => $this->director->settings($context->storeId), 'director' => $this->director->today($context->storeId)]);
    }

    #[Route('/admin/director/settings', name: 'admin_director_settings', methods: ['POST'])]
    public function settings(Request $request): RedirectResponse
    {
        $context = $this->contexts->resolve($request);
        $this->csrf($request, 'admin_director_settings');
        $this->director->saveSettings($context->storeId, $request->request->getBoolean('enabled'), $request->request->getInt('discount_percent', 10));
        $this->addFlash('success', CanonicalUiText::get('admin.director.saved'));

        return $this->redirectToRoute('admin_director');
    }

    #[Route('/admin/director/{id}/{operation}', name: 'admin_director_action', methods: ['POST'], requirements: ['id' => '\d+', 'operation' => 'apply|undo|dismiss'])]
    public function operate(int $id, string $operation, Request $request): RedirectResponse
    {
        $context = $this->contexts->resolve($request);
        $this->csrf($request, 'admin_director_' . $id);
        try {
            match ($operation) {
                'apply' => $this->addFlash('success', CanonicalUiText::get('admin.director.applied', ['count' => (string) $this->director->apply($context->storeId, $id)])),
                'undo' => $this->addFlash('success', CanonicalUiText::get('admin.director.undone', ['count' => (string) $this->director->undo($context->storeId, $id)])),
                'dismiss' => $this->director->dismiss($context->storeId, $id),
            };
        } catch (\DomainException) {
            $this->addFlash('error', CanonicalUiText::get('admin.director.gone'));
        }
        $back = (string) $request->request->get('back', '');

        return $back === 'dashboard' ? $this->redirectToRoute('admin_dashboard') : $this->redirectToRoute('admin_director');
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
