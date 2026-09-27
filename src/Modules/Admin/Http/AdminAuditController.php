<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminAuditController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    #[Route('/admin/system/activity', name: 'admin_system_activity', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query->get('search', '')), 0, 190);
        $actor = max(0, (int) $request->query->get('actor', 0));
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;
        $where = ["a.actor_type='admin'"];
        $params = [];
        if ($search !== '') {
            $where[] = '(a.action LIKE ? OR a.entity_id LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)';
            $q = '%' . $search . '%';
            array_push($params, $q, $q, $q, $q);
        }
        if ($actor > 0) {
            $where[] = 'a.actor_id=?';
            $params[] = (string) $actor;
        }
        $sqlWhere = implode(' AND ', $where);
        $count = (int) $this->db->fetchOne("SELECT COUNT(*) FROM mc_audit_log a LEFT JOIN mc_admin_user u ON u.id=CAST(a.actor_id AS UNSIGNED) WHERE {$sqlWhere}", $params);
        $pages = max(1, (int) ceil($count / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;
        $rows = $this->db->fetchAllAssociative(
            "SELECT a.id,a.actor_id,a.action,a.entity_id,a.metadata,a.created_at,u.email,u.display_name
             FROM mc_audit_log a
             LEFT JOIN mc_admin_user u ON u.id=CAST(a.actor_id AS UNSIGNED)
             WHERE {$sqlWhere}
             ORDER BY a.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        );
        foreach ($rows as &$row) {
            try { $row['meta'] = json_decode((string) ($row['metadata'] ?? '{}'), true, 16, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { $row['meta'] = []; }
        }
        unset($row);
        $admins = $this->db->fetchAllAssociative("SELECT id,display_name,email FROM mc_admin_user WHERE status='active' ORDER BY display_name,email");
        return $this->render('@storefront/admin/system/activity.html.twig', compact('rows', 'admins', 'search', 'actor', 'page', 'pages', 'count'));
    }
}
