<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Security\OutboundUrlPolicy;
use Commerce\Modules\Automation\Application\AutomationCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AutomationAdminController extends AbstractController
{
    private const MAX_RULES = 50;

    public function __construct(private readonly AdminContextResolver $contexts, private readonly Connection $db, private readonly OutboundUrlPolicy $urls)
    {
    }

    #[Route('/admin/automation', name: 'admin_automation', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $currency = (string) ($this->db->fetchOne('SELECT default_currency FROM mc_store WHERE id=?', [$context->storeId]) ?: '');

        return $this->render('@storefront/admin/automation/index.html.twig', [
            'rules' => $this->db->fetchAllAssociative('SELECT * FROM mc_automation_rule WHERE store_id=? ORDER BY id DESC', [$context->storeId]),
            'runs' => $this->db->fetchAllAssociative('SELECT r.event_ref,r.status,r.message,r.created_at,u.name rule_name FROM mc_automation_run r JOIN mc_automation_rule u ON u.id=r.rule_id WHERE u.store_id=? ORDER BY r.id DESC LIMIT 15', [$context->storeId]),
            'events' => AutomationCatalog::EVENTS,
            'actions' => AutomationCatalog::ACTIONS,
            'currency' => $currency,
        ]);
    }

    #[Route('/admin/automation/save', name: 'admin_automation_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $this->guard($request);
        $context = $this->contexts->resolve($request);
        $name = trim(mb_substr((string) $request->request->get('name', ''), 0, 160));
        $event = (string) $request->request->get('event_name', '');
        $action = (string) $request->request->get('action_type', '');
        $target = trim(mb_substr((string) $request->request->get('action_target', ''), 0, 500));
        $text = trim(mb_substr((string) $request->request->get('action_text', ''), 0, 500));
        $minRaw = str_replace([' ', ','], ['', '.'], trim((string) $request->request->get('min_total', '')));
        try {
            if ($name === '' || !in_array($event, AutomationCatalog::EVENTS, true) || !in_array($action, AutomationCatalog::ACTIONS, true)) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.automation.error_invalid'));
            }
            if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_automation_rule WHERE store_id=?', [$context->storeId]) >= self::MAX_RULES) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.automation.error_limit'));
            }
            $target = $this->validTarget($action, $target);
            $min = null;
            if ($minRaw !== '' && in_array($event, AutomationCatalog::ORDER_EVENTS, true)) {
                if (!is_numeric($minRaw) || (float) $minRaw < 0 || (float) $minRaw > 9.0e9) {
                    throw new \InvalidArgumentException(CanonicalUiText::get('admin.automation.error_min'));
                }
                $min = (int) round((float) $minRaw * 100);
            }
            $now = gmdate('Y-m-d H:i:s.u');
            $this->db->insert('mc_automation_rule', ['store_id' => $context->storeId, 'name' => $name, 'event_name' => $event, 'min_total_minor' => $min, 'action_type' => $action, 'action_target' => $target, 'action_text' => $text, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->addFlash('success', CanonicalUiText::get('admin.automation.saved'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_automation');
    }

    #[Route('/admin/automation/{id}/toggle', name: 'admin_automation_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): RedirectResponse
    {
        $this->guard($request);
        $context = $this->contexts->resolve($request);
        $this->db->executeStatement('UPDATE mc_automation_rule SET enabled=1-enabled,updated_at=? WHERE id=? AND store_id=?', [gmdate('Y-m-d H:i:s.u'), $id, $context->storeId]);

        return $this->redirectToRoute('admin_automation');
    }

    #[Route('/admin/automation/{id}/delete', name: 'admin_automation_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->guard($request);
        $context = $this->contexts->resolve($request);
        $this->db->delete('mc_automation_rule', ['id' => $id, 'store_id' => $context->storeId]);

        return $this->redirectToRoute('admin_automation');
    }

    private function validTarget(string $action, string $target): string
    {
        switch ($action) {
            case AutomationCatalog::ACTION_EMAIL:
                if (filter_var($target, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException(CanonicalUiText::get('admin.automation.error_email'));
                }

                return $target;
            case AutomationCatalog::ACTION_TELEGRAM:
                if ($target !== '' && preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{4,64})$/', $target) !== 1) {
                    throw new \InvalidArgumentException(CanonicalUiText::get('admin.automation.error_telegram'));
                }

                return $target;
            case AutomationCatalog::ACTION_WEBHOOK:
                $this->urls->assertPublicHttps($target, false);

                return $target;
            default:
                return '';
        }
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_automation', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
