<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Monthly goals and chart annotations shown on the dashboard. */
final class DashboardManageController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly Connection $db)
    {
    }

    #[Route('/admin/dashboard/goals', name: 'admin_dashboard_goals_save', methods: ['POST'])]
    public function goals(Request $request): RedirectResponse
    {
        $this->guard($request, 'admin_dashboard_goals');
        $context = $this->contexts->resolve($request);
        $now = gmdate('Y-m-d H:i:s.u');
        foreach (['revenue' => 100, 'orders' => 1] as $metric => $multiplier) {
            $raw = str_replace([' ', ','], ['', '.'], trim((string) $request->request->get('goal_' . $metric, '')));
            if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0) {
                $this->db->delete('mc_dashboard_goal', ['store_id' => $context->storeId, 'metric' => $metric]);
                continue;
            }
            $target = (int) round(min((float) $raw, 9.0e12) * $multiplier);
            $this->db->executeStatement('INSERT INTO mc_dashboard_goal (store_id,metric,target,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE target=VALUES(target),updated_at=VALUES(updated_at)', [$context->storeId, $metric, $target, $now]);
        }
        $this->addFlash('success', CanonicalUiText::get('admin.dashboard.goals_saved'));

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin/dashboard/annotations', name: 'admin_dashboard_annotation_add', methods: ['POST'])]
    public function annotate(Request $request): RedirectResponse
    {
        $this->guard($request, 'admin_dashboard_annotation');
        $context = $this->contexts->resolve($request);
        $day = (string) $request->request->get('day', '');
        $note = trim(mb_substr((string) $request->request->get('note', ''), 0, 190));
        $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && \DateTimeImmutable::createFromFormat('!Y-m-d', $day) !== false;
        if (!$valid || $note === '') {
            $this->addFlash('error', CanonicalUiText::get('admin.dashboard.annotation_invalid'));

            return $this->redirectToRoute('admin_dashboard');
        }
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_dashboard_annotation WHERE store_id=?', [$context->storeId]);
        if ($count >= 200) {
            $this->addFlash('error', CanonicalUiText::get('admin.dashboard.annotation_limit'));

            return $this->redirectToRoute('admin_dashboard');
        }
        $this->db->insert('mc_dashboard_annotation', ['store_id' => $context->storeId, 'day' => $day, 'note' => $note, 'created_at' => gmdate('Y-m-d H:i:s.u')]);
        $this->addFlash('success', CanonicalUiText::get('admin.dashboard.annotation_added'));

        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/admin/dashboard/annotations/{id}/delete', name: 'admin_dashboard_annotation_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(int $id, Request $request): RedirectResponse
    {
        $this->guard($request, 'admin_dashboard_annotation');
        $context = $this->contexts->resolve($request);
        $this->db->delete('mc_dashboard_annotation', ['id' => $id, 'store_id' => $context->storeId]);

        return $this->redirectToRoute('admin_dashboard');
    }

    private function guard(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
