<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Storefront\Infrastructure\PickupPointRepository;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContactSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → Settings → "Contacts & pickup": what the storefront contact page, footer and "Self pickup" delivery show. */
final class StorefrontContactAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly StorefrontContactSettings $contact,
        private readonly PickupPointRepository $pickup,
    ) {
    }

    #[Route('/admin/system/storefront-contacts', name: 'admin_system_storefront_contacts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $editId = (int) $request->query->get('point', 0);
        $points = $this->pickup->all($context->storeId);
        $editing = null;
        foreach ($points as $point) {
            if ($point['id'] === $editId) {
                $editing = $point;
            }
        }

        return $this->render('@storefront/admin/system/storefront_contacts.html.twig', [
            's' => $this->contact->get($context->storeId),
            'social_networks' => array_keys(StorefrontContactSettings::SOCIAL),
            'points' => $points,
            'editing' => $editing,
        ]);
    }

    #[Route('/admin/system/storefront-contacts/save', name: 'admin_system_storefront_contacts_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_storefront_contacts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $this->contact->save($context->storeId, $request->request->all());
            $this->addFlash('success', CanonicalUiText::get('admin.storefront_contacts.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_system_storefront_contacts');
    }

    #[Route('/admin/system/storefront-contacts/pickup', name: 'admin_system_storefront_pickup_save', methods: ['POST'])]
    public function savePickup(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_storefront_pickup', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $id = (int) $request->request->get('id', 0);
            $this->pickup->save($context->storeId, $id > 0 ? $id : null, $request->request->all());
            $this->addFlash('success', CanonicalUiText::get('admin.storefront_contacts.pickup_saved'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_system_storefront_contacts');
    }

    #[Route('/admin/system/storefront-contacts/pickup/{id}/delete', name: 'admin_system_storefront_pickup_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePickup(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_storefront_pickup', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $this->pickup->delete($context->storeId, $id);
        $this->addFlash('success', CanonicalUiText::get('admin.storefront_contacts.pickup_deleted'));

        return $this->redirectToRoute('admin_system_storefront_contacts');
    }
}
