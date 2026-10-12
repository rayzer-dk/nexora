<?php

declare(strict_types=1);

namespace Commerce\Modules\Affiliate\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Affiliate\Application\AffiliateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → Marketing → Affiliates: partners, their links, what they brought and what is owed. */
final class AffiliateAdminController extends AbstractController
{
    public function __construct(private readonly AffiliateService $affiliates, private readonly AdminContextResolver $contexts)
    {
    }

    #[Route('/admin/commerce/affiliates', name: 'admin_commerce_affiliates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);

        return $this->render('@storefront/admin/commerce/affiliates.html.twig', ['rows' => $this->affiliates->overview($context->storeId), 'base' => rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/'), 'currency' => $context->currency]);
    }

    #[Route('/admin/commerce/affiliates/create', name: 'admin_commerce_affiliate_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        $context = $this->contexts->resolve($request);
        $this->csrf($request, 'admin_affiliate');
        try {
            $this->affiliates->create($context->storeId, (string) $request->request->get('name', ''), (string) $request->request->get('email', ''), (string) $request->request->get('percent', '5'));
            $this->addFlash('success', CanonicalUiText::get('admin.affiliate.created'));
        } catch (\DomainException) {
            $this->addFlash('error', CanonicalUiText::get('admin.affiliate.bad_input'));
        }

        return $this->redirectToRoute('admin_commerce_affiliates');
    }

    #[Route('/admin/commerce/affiliates/{id}/{operation}', name: 'admin_commerce_affiliate_action', methods: ['POST'], requirements: ['id' => '\d+', 'operation' => 'pay|pause|resume'])]
    public function operate(int $id, string $operation, Request $request): RedirectResponse
    {
        $context = $this->contexts->resolve($request);
        $this->csrf($request, 'admin_affiliate');
        if ($operation === 'pay') {
            $minor = $this->affiliates->payOut($context->storeId, $id);
            $this->addFlash('success', CanonicalUiText::get('admin.affiliate.paid', ['amount' => number_format($minor / 100, 2, '.', ' ') . ' ' . $context->currency]));
        } else {
            $this->affiliates->setStatus($context->storeId, $id, $operation === 'resume');
            $this->addFlash('success', CanonicalUiText::get('admin.affiliate.saved'));
        }

        return $this->redirectToRoute('admin_commerce_affiliates');
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
