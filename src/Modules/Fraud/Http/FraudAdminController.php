<?php

declare(strict_types=1);

namespace Commerce\Modules\Fraud\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Fraud\Application\FraudService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Anti-fraud review queue, blocklist and account-security switches. */
final class FraudAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly FraudService $fraud)
    {
    }

    #[Route('/admin/system/fraud', name: 'admin_system_fraud', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;

        return $this->render('@storefront/admin/system/fraud.html.twig', [
            'settings' => $this->fraud->settings($storeId),
            'queue' => $this->fraud->review($storeId),
            'blocklist' => $this->fraud->blocklist($storeId),
            'kinds' => FraudService::KINDS,
        ]);
    }

    #[Route('/admin/system/fraud/save', name: 'admin_system_fraud_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->fraud->saveSettings($this->contexts->resolve($request)->storeId, $request->request->all());
        $this->addFlash('success', CanonicalUiText::get('admin.fraud.saved'));

        return $this->redirectToRoute('admin_system_fraud');
    }

    #[Route('/admin/system/fraud/block', name: 'admin_system_fraud_block_add', methods: ['POST'])]
    public function addBlock(Request $request): RedirectResponse
    {
        $this->guard($request);
        try {
            $this->fraud->addBlock($this->contexts->resolve($request)->storeId, (string) $request->request->get('kind', ''), (string) $request->request->get('value', ''), (string) $request->request->get('note', ''));
            $this->addFlash('success', CanonicalUiText::get('admin.fraud.block_added'));
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', CanonicalUiText::get('admin.fraud.block_invalid'));
        }

        return $this->redirectToRoute('admin_system_fraud');
    }

    #[Route('/admin/system/fraud/block/{id}/delete', name: 'admin_system_fraud_block_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteBlock(int $id, Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->fraud->removeBlock($this->contexts->resolve($request)->storeId, $id);
        $this->addFlash('success', CanonicalUiText::get('admin.fraud.block_removed'));

        return $this->redirectToRoute('admin_system_fraud');
    }

    #[Route('/admin/system/fraud/order/{orderId}/decide', name: 'admin_system_fraud_decide', methods: ['POST'], requirements: ['orderId' => '\d+'])]
    public function decide(int $orderId, Request $request): RedirectResponse
    {
        $this->guard($request);
        $decision = (string) $request->request->get('decision', '');
        try {
            $this->fraud->decide($this->contexts->resolve($request)->storeId, $orderId, $decision, (string) $this->getUser()?->getUserIdentifier(), $request->request->has('block'));
            $this->addFlash('success', CanonicalUiText::get('admin.fraud.decided'));
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', CanonicalUiText::get('admin.fraud.block_invalid'));
        }

        return $this->redirectToRoute('admin_system_fraud');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_fraud', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
