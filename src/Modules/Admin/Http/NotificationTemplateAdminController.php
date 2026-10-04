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
            $locale = in_array($context->locale, $codes, true) ? $context->locale : (string) ($codes[0] ?? 'en-US');
        }
        $stored = $this->templates->forLocale($context->storeId, $locale);
        $items = [];
        foreach (NotificationTemplateService::CATALOG as $code => $placeholders) {
            $items[] = ['code' => $code, 'placeholders' => $placeholders, 'stored' => $stored[$code] ?? null, 'default' => $this->templates->defaults($code, $locale)];
        }

        return $this->render('@storefront/admin/commerce/notification_templates.html.twig', ['locales' => $locales, 'locale' => $locale, 'items' => $items]);
    }

    private const LAYOUTS = ['order.created' => 'order_created', 'order.status_updated' => 'order_status', 'inquiry_received' => 'generic', 'newsletter.confirm' => 'generic'];

    /** Renders the e-mail exactly as customers get it, with sample data, for the live preview and the HTML tab. */
    #[Route('/admin/commerce/notification-templates/preview', name: 'admin_commerce_notification_templates_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_notification_templates', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false], 403);
        }
        $code = (string) $request->request->get('code', '');
        $layout = self::LAYOUTS[$code] ?? null;
        if ($layout === null) {
            return $this->json(['ok' => false], 404);
        }
        $storeName = (string) ($this->db->fetchOne('SELECT name FROM mc_store WHERE id=?', [$context->storeId]) ?: 'Nexora');
        $vars = $this->templates->sampleVariables($storeName);
        $subject = $this->templates->render(mb_substr((string) $request->request->get('subject', ''), 0, 255), $vars);
        $isHtml = $request->request->getBoolean('is_html');
        $body = $this->templates->render($isHtml ? $this->templates->cleanHtml(mb_substr((string) $request->request->get('body', ''), 0, 30000)) : mb_substr(strip_tags((string) $request->request->get('body', '')), 0, 8000), $vars);
        $html = $this->renderView('@storefront/email/' . $layout . '.html.twig', [
            'notification_subject' => $subject, 'notification_text' => $body, 'custom_body' => $isHtml ? '' : $body, 'custom_html' => $isHtml ? $body : '',
            'store_name' => $storeName, 'locale' => (string) $request->request->get('locale', 'en-US'),
            'order_number' => $vars['order_number'], 'customer_name' => $vars['customer_name'], 'total' => $vars['total'],
            'items' => [['name' => 'Sample product', 'sku' => 'DEMO-001', 'quantity' => 1, 'unit_code' => 'pcs', 'line_total_minor' => 124900]],
            'total_minor' => 124900, 'currency' => 'UAH', 'action_url' => '#', 'footer_text' => '', 'unsubscribe_url' => '#',
        ]);

        return $this->json(['ok' => true, 'subject' => $subject, 'html' => $html]);
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
                $this->templates->save($context->storeId, $code, $locale, (string) $request->request->get('subject', ''), (string) $request->request->get('body', ''), $request->request->get('enabled') === '1', $request->request->getBoolean('is_html'));
                $this->addFlash('success', CanonicalUiText::get('admin.tpl.saved'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_commerce_notification_templates', ['locale' => $locale]);
    }
}
