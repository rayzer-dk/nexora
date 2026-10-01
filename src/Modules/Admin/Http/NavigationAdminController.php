<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Navigation\Application\NavigationManager;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class NavigationAdminController extends AbstractController
{
    /** Storefront pages offered in the "opens" list (path => ui_text key). */
    private const PAGES = [
        '/catalog' => 'catalog', '/about-us' => 'about', '/contact' => 'contacts', '/shipping' => 'shipping', '/payment' => 'payment',
        '/returns' => 'returns_exchange', '/warranty' => 'warranty', '/faq' => 'faq', '/blog' => 'blog', '/forum' => 'forum',
        '/privacy-policy' => 'privacy_policy', '/cookie-policy' => 'cookie_policy', '/terms-and-conditions' => 'terms',
    ];

    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly NavigationManager $navigation,
        private readonly Connection $db,
    ) {
    }

    #[Route('/admin/appearance/navigation', name: 'admin_appearance_navigation', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $menu = (string) $request->query->get('menu', 'header');
        if (!in_array($menu, NavigationManager::MENUS, true)) {
            $menu = 'header';
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT n.id,n.parent_id,n.menu_code,n.item_type,n.target_ref,n.url,n.status,n.sort_order,n.open_new_tab FROM mc_navigation_item n WHERE n.store_id=? AND n.menu_code=? ORDER BY n.parent_id IS NOT NULL,n.sort_order,n.id',
            [$ctx->storeId, $menu],
        );
        $locales = $this->db->fetchAllAssociative(
            'SELECT l.code,COALESCE(NULLIF(l.native_name,\'\'),l.name,l.code) name FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.is_default DESC,sl.sort_order',
            [$ctx->storeId],
        );
        $categories = $this->categoryOptions($ctx->storeId, $ctx->locale);
        $names = [];
        foreach ($categories as $option) {
            $names[$option['uuid']] = $option['name'];
        }
        foreach ($rows as &$row) {
            $row['translations'] = $this->db->fetchAllKeyValue('SELECT locale,label FROM mc_navigation_item_translation WHERE navigation_item_id=?', [(int) $row['id']]);
            $row['choice'] = (string) $row['item_type'] === 'custom' ? 'custom' : $row['item_type'] . ':' . $row['target_ref'];
            $row['display'] = $row['translations'] !== [] ? (string) reset($row['translations']) : ($names[strtolower((string) $row['target_ref'])] ?? (string) $row['target_ref']);
        }
        unset($row);
        $editId = $request->query->getInt('edit', 0);
        $editing = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $editId) {
                $editing = $row;
                break;
            }
        }

        return $this->render('@storefront/admin/appearance/navigation.html.twig', [
            'items' => $rows, 'locales' => $locales, 'menu' => $menu, 'editing' => $editing,
            'categories' => $categories, 'pages' => self::PAGES,
        ]);
    }

    #[Route('/admin/appearance/navigation/save', name: 'admin_appearance_navigation_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('navigation_save', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $ctx = $this->contexts->resolve($request);
        $labels = [];
        foreach ($request->request->all('label') as $locale => $label) {
            if (is_scalar($label)) {
                $labels[(string) $locale] = (string) $label;
            }
        }
        // "Opens" is one combined choice: "custom", "category:<uuid>", "page:</path>" or "product:<uuid>".
        $choice = (string) $request->request->get('choice', 'custom');
        [$type, $target] = array_pad(explode(':', $choice, 2), 2, '');
        $menu = (string) $request->request->get('menu_code', 'header');
        try {
            $this->navigation->save($ctx->storeId, $request->request->getInt('id', 0) ?: null, [
                'menu_code' => $menu, 'parent_id' => $request->request->getInt('parent_id', 0), 'item_type' => $type === '' ? 'custom' : $type,
                'target_ref' => $type === 'category' || $type === 'product' ? strtolower($target) : $target,
                'url' => $request->request->get('url', ''), 'status' => $request->request->get('status', 'active'),
                'sort_order' => $request->request->getInt('sort_order', 0), 'open_new_tab' => $request->request->getBoolean('open_new_tab'),
            ], $labels);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.navigationadmincontroller.punkt_meniu_zberezheno'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_appearance_navigation', ['menu' => $menu]);
    }

    #[Route('/admin/appearance/navigation/seed', name: 'admin_appearance_navigation_seed', methods: ['POST'])]
    public function seed(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('navigation_seed', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $ctx = $this->contexts->resolve($request);
        $menu = (string) $request->request->get('menu_code', 'header');
        $created = $this->navigation->seedTopCategories($ctx->storeId, $menu);
        $this->addFlash('success', sprintf(\Commerce\Core\I18n\CanonicalUiText::get('admin.appearance.navigation.seeded'), $created));

        return $this->redirectToRoute('admin_appearance_navigation', ['menu' => in_array($menu, NavigationManager::MENUS, true) ? $menu : 'header']);
    }

    #[Route('/admin/appearance/navigation/{id}/delete', name: 'admin_appearance_navigation_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('navigation_delete_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $ctx = $this->contexts->resolve($request);
        $menu = (string) $this->db->fetchOne('SELECT menu_code FROM mc_navigation_item WHERE id=? AND store_id=?', [$id, $ctx->storeId]);
        $this->navigation->delete($ctx->storeId, $id);

        return $this->redirectToRoute('admin_appearance_navigation', ['menu' => in_array($menu, NavigationManager::MENUS, true) ? $menu : 'header']);
    }

    /** @return list<array{uuid:string,name:string,depth:int}> */
    private function categoryOptions(int $storeId, string $locale): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.id,c.parent_id,c.public_id,COALESCE(ct.name,ctd.name,'') name FROM mc_category c
             JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active'
             LEFT JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=?
             LEFT JOIN mc_category_translation ctd ON ctd.category_id=c.id AND ctd.store_id=? AND ctd.locale=(SELECT default_locale FROM mc_store WHERE id=?)
             WHERE c.status='active' ORDER BY sc.sort_order,c.sort_order,c.id",
            [$storeId, $storeId, $locale, $storeId, $storeId],
        );
        $by = [];
        foreach ($rows as $row) {
            $by[$row['parent_id'] !== null ? (int) $row['parent_id'] : 0][] = $row;
        }
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$by, &$out): void {
            foreach ($by[$parent] ?? [] as $row) {
                if ((string) $row['name'] !== '') {
                    $out[] = ['uuid' => strtolower(Uuid::fromBinary((string) $row['public_id'])->toRfc4122()), 'name' => (string) $row['name'], 'depth' => $depth];
                }
                if ($depth < 4) {
                    $walk((int) $row['id'], $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $out;
    }
}
