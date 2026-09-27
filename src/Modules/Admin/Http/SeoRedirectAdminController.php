<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoRedirectAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly Connection $db,
    ) {}

    #[Route('/admin/system/seo-redirects', name: 'admin_system_seo_redirects', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 50;
        $search = mb_substr(trim((string)$request->query->get('search', '')), 0, 255);
        $locale = mb_substr(trim((string)$request->query->get('locale', '')), 0, 16);
        $entityType = mb_substr(trim((string)$request->query->get('entity_type', '')), 0, 32);

        $where = ['r.store_id = ?'];
        $params = [$context->storeId];
        if ($search !== '') {
            $where[] = '(r.source_path LIKE ? OR s.path LIKE ? OR s.slug LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if ($locale !== '') {
            $where[] = 'r.locale = ?';
            $params[] = $locale;
        }
        if ($entityType !== '') {
            $where[] = 's.entity_type = ?';
            $params[] = $entityType;
        }
        $sqlWhere = implode(' AND ', $where);
        $count = (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM mc_seo_redirect r INNER JOIN mc_seo_route s ON s.id=r.route_id WHERE ' . $sqlWhere,
            $params,
        );
        $pages = max(1, (int)ceil($count / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->fetchAllAssociative(
            'SELECT r.id,r.locale,r.source_path,r.status_code,r.reason,r.created_at,r.last_hit_at,r.hit_count,s.path AS target_path,s.slug AS target_slug,s.entity_type,COALESCE(sl.url_prefix,\'\') AS url_prefix FROM mc_seo_redirect r INNER JOIN mc_seo_route s ON s.id=r.route_id LEFT JOIN mc_store_locale sl ON sl.store_id=r.store_id AND sl.locale_code=r.locale WHERE ' . $sqlWhere . ' ORDER BY r.created_at DESC,r.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params,
        );
        $locales = $this->db->fetchFirstColumn('SELECT DISTINCT locale FROM mc_seo_redirect WHERE store_id=? ORDER BY locale', [$context->storeId]);
        $entityTypes = $this->db->fetchFirstColumn('SELECT DISTINCT s.entity_type FROM mc_seo_redirect r INNER JOIN mc_seo_route s ON s.id=r.route_id WHERE r.store_id=? ORDER BY s.entity_type', [$context->storeId]);

        return $this->render('@storefront/admin/system/seo_redirects.html.twig', [
            'rows' => $rows,
            'count' => $count,
            'page' => $page,
            'pages' => $pages,
            'search' => $search,
            'locale' => $locale,
            'entity_type' => $entityType,
            'locales' => $locales,
            'entity_types' => $entityTypes,
        ]);
    }

    #[Route('/admin/system/seo-redirects/{id}/delete', name: 'admin_system_seo_redirect_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('seo_redirect_delete_' . $id, (string)$request->request->get('_row_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->db->executeStatement('DELETE FROM mc_seo_redirect WHERE id=? AND store_id=?', [$id, $context->storeId]);
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.system.seo_redirects.deleted'));
        return $this->redirectToRoute('admin_system_seo_redirects');
    }

    #[Route('/admin/system/seo-redirects/delete-selected', name: 'admin_system_seo_redirect_delete_selected', methods: ['POST'])]
    public function deleteSelected(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('seo_redirect_delete_selected', (string)$request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$request->request->all('ids')), static fn(int $id): bool => $id > 0)));
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->executeStatement('DELETE FROM mc_seo_redirect WHERE store_id=? AND id IN (' . $placeholders . ')', [$context->storeId, ...$ids]);
        }
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.system.seo_redirects.deleted_selected'));
        return $this->redirectToRoute('admin_system_seo_redirects');
    }
}
