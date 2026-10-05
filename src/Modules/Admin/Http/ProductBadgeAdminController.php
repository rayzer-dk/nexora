<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Catalog\Application\ProductBadgeService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductBadgeAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly ProductBadgeService $badges, private readonly Connection $db)
    {
    }

    #[Route('/admin/catalog/badges', name: 'admin_catalog_badges', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $this->badges->materializeDefaults($ctx->storeId);
        $rules = $this->badges->rules($ctx->storeId, true);
        foreach ($rules as &$rule) {
            $rule['skus'] = '';
            if ($rule['kind'] === 'manual') {
                $rule['skus'] = implode(', ', $this->db->fetchFirstColumn(
                    'SELECT v.sku FROM mc_product_badge_product bp JOIN mc_product_variant v ON v.product_id=bp.product_id WHERE bp.badge_id=? GROUP BY bp.product_id ORDER BY MIN(v.sort_order),MIN(v.id) LIMIT 500',
                    [$rule['id']],
                ));
            }
        }
        unset($rule);

        return $this->render('@storefront/admin/catalog/badges.html.twig', [
            'rules' => $rules, 'tones' => ProductBadgeService::TONES, 'locale' => $ctx->locale,
            'locales' => $this->db->fetchAllAssociative('SELECT sl.locale_code code,COALESCE(l.native_name,sl.locale_code) native_name FROM mc_store_locale sl LEFT JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.is_default DESC,sl.locale_code', [$ctx->storeId]),
        ]);
    }

    #[Route('/admin/catalog/badges/save', name: 'admin_catalog_badge_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('catalog_badges', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $ctx = $this->contexts->resolve($request);
        $id = $request->request->getInt('id') ?: null;
        try {
            $existing = $id !== null ? $this->db->fetchAssociative('SELECT kind,code FROM mc_product_badge WHERE id=? AND store_id=?', [$id, $ctx->storeId]) : false;
            $id = $this->badges->save($ctx->storeId, $id, [
                'code' => is_array($existing) ? $existing['code'] : (string) $request->request->get('code', ''),
                'kind' => is_array($existing) ? $existing['kind'] : (string) $request->request->get('kind', 'manual'),
                'tone' => (string) $request->request->get('tone', 'primary') === 'custom' ? (string) $request->request->get('tone_custom', '') : (string) $request->request->get('tone', 'primary'),
                'labels' => $this->labelsFromRequest($request),
                'icon' => (string) $request->request->get('icon', ''),
                'window_days' => $request->request->getInt('window_days', 30), 'min_sold' => $request->request->getInt('min_sold', 5),
                'priority' => $request->request->getInt('priority', 100), 'enabled' => $request->request->getBoolean('enabled'),
            ]);
            $kind = is_array($existing) ? $existing['kind'] : (string) $request->request->get('kind', 'manual');
            if ($kind === 'manual') {
                $skus = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $request->request->get('skus', '')) ?: [])));
                $ids = $skus === [] ? [] : array_map('intval', $this->db->fetchFirstColumn('SELECT DISTINCT product_id FROM mc_product_variant WHERE sku IN (' . implode(',', array_fill(0, count($skus), '?')) . ')', $skus));
                $this->badges->assign($ctx->storeId, $id, $ids);
            }
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.badges.saved'));
        } catch (\DomainException|\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('admin.badges.invalid'));
        }

        return $this->redirectToRoute('admin_catalog_badges');
    }

    /**
     * One text per language of the store; the first filled one (the default language comes first) is also the common fallback.
     *
     * @return array<string,string>
     */
    private function labelsFromRequest(Request $request): array
    {
        $labels = [];
        $posted = $request->request->all('label');
        foreach ($posted as $locale => $text) {
            $text = trim((string) $text);
            if ($text !== '' && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', (string) $locale) === 1) {
                $labels[(string) $locale] = $text;
            }
        }
        $labels = ['default' => (string) (reset($labels) ?: '')] + $labels;
        if ($labels['default'] === '') {
            $labels['default'] = trim((string) $request->request->get('label_default', ''));
        }

        return $labels;
    }

    #[Route('/admin/catalog/badges/{id}/delete', name: 'admin_catalog_badge_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('catalog_badges', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $ctx = $this->contexts->resolve($request);
        $this->badges->delete($ctx->storeId, $id);
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.badges.deleted'));

        return $this->redirectToRoute('admin_catalog_badges');
    }
}
