<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Notification\Application\NotificationTemplateService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationTemplateAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly NotificationTemplateService $templates,
        private readonly Connection $db,
    ) {
    }

    #[Route('/admin/commerce/notification-templates', name: 'admin_commerce_notification_templates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $locales = $this->db->fetchAllAssociative('SELECT code,native_name FROM mc_locale WHERE enabled=1 ORDER BY native_name,code');
        $codes = array_column($locales, 'code');
        $locale = (string) $request->query->get('locale', '');
        if (!in_array($locale, $codes, true)) {
            $locale = (string) ($codes[0] ?? $context->locale ?? 'en-US');
        }
        $stored = $this->templates->forLocale($context->storeId, $locale);
        $items = [];
        foreach (NotificationTemplateService::CATALOG as $code => $placeholders) {
            $items[] = ['code' => $code, 'placeholders' => $placeholders, 'stored' => $stored[$code] ?? null];
        }

        return $this->render('@storefront/admin/commerce/notification_templates.html.twig', ['locales' => $locales, 'locale' => $locale, 'items' => $items]);
    }

    #[Route('/admin/commerce/notification-templates/save', name: 'admin_commerce_notification_templates_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_notification_templates', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $code = (string) $request->request->get('code', '');
        $locale = (string) $request->request->get('locale', '');
        try {
            if ($request->request->get('action') === 'reset') {
                $this->templates->reset($context->storeId, $code, $locale);
                $this->addFlash('success', CanonicalUiText::get('admin.tpl.reset_done'));
            } else {
                $this->templates->save($context->storeId, $code, $locale, (string) $request->request->get('subject', ''), (string) $request->request->get('body', ''), $request->request->get('enabled') === '1');
                $this->addFlash('success', CanonicalUiText::get('admin.tpl.saved'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_commerce_notification_templates', ['locale' => $locale]);
    }
}
