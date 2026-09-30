<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Appearance\Infrastructure\ChatWidgetSettings;
use Commerce\Modules\Appearance\Infrastructure\ContactWidgetSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContactWidgetAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly ContactWidgetSettings $settings, private readonly ChatWidgetSettings $chat)
    {
    }

    #[Route('/admin/appearance/contact-widget', name: 'admin_appearance_contact_widget', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_contact_widget', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            try {
                $this->settings->save($context->storeId, $request->request->all());
                $this->addFlash('success', CanonicalUiText::get('admin.cw.saved'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('admin_appearance_contact_widget');
        }

        return $this->render('@storefront/admin/appearance/contact_widget.html.twig', ['s' => $this->settings->get($context->storeId), 'chat' => $this->chat->get($context->storeId), 'chat_providers' => ChatWidgetSettings::PROVIDERS]);
    }

    #[Route('/admin/appearance/chat-widget', name: 'admin_appearance_chat_widget_save', methods: ['POST'])]
    public function saveChat(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_chat_widget', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $this->chat->save($context->storeId, (string) $request->request->get('provider', 'none'), (string) $request->request->get('embed', ''), (string) $request->request->get('base_url', ''));
            $this->addFlash('success', CanonicalUiText::get('admin.chat.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_appearance_contact_widget');
    }
}
