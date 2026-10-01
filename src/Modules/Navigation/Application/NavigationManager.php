<?php

declare(strict_types=1);

namespace Commerce\Modules\Navigation\Application;

use Commerce\Core\Id\PublicIdFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Header / footer / utility menus managed in Appearance → Navigation.
 *
 * An item is a custom link (URL), a category (by public id), a product (by public id) or a storefront page (path).
 * Labels are stored per language; an item without a label in the visitor's language falls back to the default-language
 * label, and a category item without any label falls back to the category name, so "add a category" needs no typing.
 */
final class NavigationManager
{
    public const MENUS = ['header', 'footer', 'utility'];
    public const TYPES = ['custom', 'category', 'product', 'page'];

    public function __construct(private readonly Connection $db, private readonly PublicIdFactory $ids)
    {
    }

    /** @return list<array<string,mixed>> */
    public function items(int $storeId, string $menuCode, string $locale): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT n.id,n.parent_id,n.item_type,n.target_ref,n.url,n.open_new_tab,COALESCE(t.label,tf.label,'') label,COALESCE(t.badge,tf.badge) badge
             FROM mc_navigation_item n
             LEFT JOIN mc_navigation_item_translation t ON t.navigation_item_id=n.id AND t.locale=?
             LEFT JOIN mc_navigation_item_translation tf ON tf.navigation_item_id=n.id AND tf.locale=(SELECT default_locale FROM mc_store WHERE id=n.store_id)
             WHERE n.store_id=? AND n.menu_code=? AND n.status='active'
             ORDER BY n.parent_id IS NOT NULL,n.sort_order,n.id",
            [$locale, $storeId, $menuCode],
        );
        $categoryNames = $this->categoryNames($storeId, $locale, $rows);
        $byParent = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            if ((string) $row['label'] === '' && (string) $row['item_type'] === 'category') {
                $row['label'] = $categoryNames[strtolower((string) $row['target_ref'])] ?? '';
            }
            $row['url'] = $this->resolveUrl($storeId, $locale, $row);
            if ($row['url'] === '' || (string) $row['label'] === '') {
                continue; // a dead entry (deleted category, empty link) is never shown on the storefront
            }
            $row['children'] = [];
            $byParent[$row['parent_id'] ?? 0][] = $row;
        }
        $build = function (int $parent, int $depth = 0) use (&$build, &$byParent): array {
            if ($depth > 4) {
                return [];
            }
            $out = [];
            foreach ($byParent[$parent] ?? [] as $row) {
                $row['children'] = $build((int) $row['id'], $depth + 1);
                $out[] = $row;
            }

            return $out;
        };

        return $build(0);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $labels
     */
    public function save(int $storeId, ?int $id, array $data, array $labels): int
    {
        $menu = in_array((string) ($data['menu_code'] ?? ''), self::MENUS, true) ? (string) $data['menu_code'] : 'header';
        $type = in_array((string) ($data['item_type'] ?? ''), self::TYPES, true) ? (string) $data['item_type'] : 'custom';
        $url = $this->cleanUrl((string) ($data['url'] ?? ''));
        $target = mb_substr(trim((string) ($data['target_ref'] ?? '')), 0, 255);
        $parent = max(0, (int) ($data['parent_id'] ?? 0));
        $sort = (int) ($data['sort_order'] ?? 0);
        $now = gmdate('Y-m-d H:i:s.u');

        $labels = array_filter(array_map(static fn (mixed $label): string => mb_substr(trim(strip_tags((string) $label)), 0, 190), $labels), static fn (string $label): bool => $label !== '');
        if ($type === 'custom' && $url === '') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.appearance.navigation.err_url_required'));
        }
        if (in_array($type, ['category', 'product', 'page'], true) && $target === '') {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.appearance.navigation.err_target_required'));
        }
        if ($type !== 'category' && $labels === []) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('admin.appearance.navigation.err_label_required'));
        }
        if ($type === 'page') {
            $target = '/' . ltrim($target, '/');
        }
        if ($parent > 0) {
            $parentRow = $this->db->fetchAssociative('SELECT id,parent_id FROM mc_navigation_item WHERE id=? AND store_id=? AND menu_code=?', [$parent, $storeId, $menu]);
            if (!$parentRow) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.navigation.application.navigationmanager.batkivskyi_punkt_ne_nalezhyt_tsomu_meniu'));
            }
            if ($id && $this->wouldCreateCycle($storeId, $id, $parent)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.navigation.application.navigationmanager.nemozhlyvo_stvoryty_tsyklichnu_vkladenist_meniu'));
            }
        }

        return $this->db->transactional(function (Connection $db) use ($storeId, $id, $menu, $type, $url, $target, $parent, $sort, $now, $labels, $data): int {
            $payload = [
                'store_id' => $storeId,
                'menu_code' => $menu,
                'parent_id' => $parent > 0 ? $parent : null,
                'item_type' => $type,
                'target_ref' => $target !== '' ? $target : null,
                'url' => $type === 'custom' && $url !== '' ? $url : null,
                'status' => ($data['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
                'sort_order' => $sort,
                'open_new_tab' => !empty($data['open_new_tab']) ? 1 : 0,
                'updated_at' => $now,
            ];
            if ($id && $db->fetchOne('SELECT id FROM mc_navigation_item WHERE id=? AND store_id=?', [$id, $storeId]) !== false) {
                if ($parent === $id) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.navigation.application.navigationmanager.punkt_meniu_ne_mozhe_buty_batkom_samoho_sebe'));
                }
                $db->update('mc_navigation_item', $payload, ['id' => $id]);
                // A saved form is the full picture of the labels: a cleared language is removed, not kept stale.
                $db->delete('mc_navigation_item_translation', ['navigation_item_id' => $id]);
            } else {
                $payload['public_id'] = $this->ids->binary();
                $payload['created_at'] = $now;
                $db->insert('mc_navigation_item', $payload);
                $id = (int) $db->lastInsertId();
            }
            foreach ($labels as $locale => $label) {
                $db->insert('mc_navigation_item_translation', ['navigation_item_id' => $id, 'locale' => (string) $locale, 'label' => $label, 'badge' => null]);
            }

            return $id;
        });
    }

    public function delete(int $storeId, int $id): void
    {
        $this->db->delete('mc_navigation_item', ['id' => $id, 'store_id' => $storeId]);
    }

    /**
     * Adds every top-level storefront category that is not in the menu yet as a "category" item (no typing: the label is
     * the category name). Existing items and their order are untouched. Returns the number of created items.
     */
    public function seedTopCategories(int $storeId, string $menuCode): int
    {
        if (!in_array($menuCode, self::MENUS, true)) {
            return 0;
        }
        $categories = $this->db->fetchAllAssociative(
            "SELECT c.public_id FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active'
             WHERE c.status='active' AND c.parent_id IS NULL ORDER BY sc.sort_order,c.sort_order,c.id",
            [$storeId],
        );
        $existing = array_map('strtolower', array_map('strval', $this->db->fetchFirstColumn("SELECT target_ref FROM mc_navigation_item WHERE store_id=? AND menu_code=? AND item_type='category'", [$storeId, $menuCode])));
        $order = (int) $this->db->fetchOne('SELECT COALESCE(MAX(sort_order),0) FROM mc_navigation_item WHERE store_id=? AND menu_code=? AND parent_id IS NULL', [$storeId, $menuCode]);
        $created = 0;
        $now = gmdate('Y-m-d H:i:s.u');
        foreach ($categories as $category) {
            $uuid = strtolower(Uuid::fromBinary((string) $category['public_id'])->toRfc4122());
            if (in_array($uuid, $existing, true)) {
                continue;
            }
            $order += 10;
            $this->db->insert('mc_navigation_item', [
                'public_id' => $this->ids->binary(), 'store_id' => $storeId, 'menu_code' => $menuCode, 'parent_id' => null, 'item_type' => 'category',
                'target_ref' => $uuid, 'url' => null, 'status' => 'active', 'sort_order' => $order, 'open_new_tab' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            ++$created;
        }

        return $created;
    }

    /**
     * Category names (visitor language, else the store default language) for category items without a label.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,string> lower-case public id => name
     */
    private function categoryNames(int $storeId, string $locale, array $rows): array
    {
        $binary = [];
        foreach ($rows as $row) {
            if ((string) $row['item_type'] === 'category' && (string) $row['label'] === '' && Uuid::isValid((string) $row['target_ref'])) {
                $binary[] = Uuid::fromString((string) $row['target_ref'])->toBinary();
            }
        }
        if ($binary === []) {
            return [];
        }
        $found = $this->db->fetchAllAssociative(
            'SELECT c.public_id,COALESCE(ct.name,ctd.name) name FROM mc_category c
             LEFT JOIN mc_category_translation ct ON ct.category_id=c.id AND ct.store_id=? AND ct.locale=?
             LEFT JOIN mc_category_translation ctd ON ctd.category_id=c.id AND ctd.store_id=? AND ctd.locale=(SELECT default_locale FROM mc_store WHERE id=?)
             WHERE c.public_id IN (' . implode(',', array_fill(0, count($binary), '?')) . ')',
            [$storeId, $locale, $storeId, $storeId, ...$binary],
        );
        $out = [];
        foreach ($found as $row) {
            if ((string) $row['name'] !== '') {
                $out[strtolower(Uuid::fromBinary((string) $row['public_id'])->toRfc4122())] = (string) $row['name'];
            }
        }

        return $out;
    }

    private function wouldCreateCycle(int $storeId, int $id, int $parentId): bool
    {
        $seen = [];
        $cursor = $parentId;
        $depth = 0;
        while ($cursor > 0 && $depth++ < 20) {
            if ($cursor === $id || isset($seen[$cursor])) {
                return true;
            }
            $seen[$cursor] = true;
            $next = $this->db->fetchOne('SELECT parent_id FROM mc_navigation_item WHERE id=? AND store_id=?', [$cursor, $storeId]);
            if ($next === false || $next === null) {
                return false;
            }
            $cursor = (int) $next;
        }

        return $cursor > 0;
    }

    private function cleanUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return mb_substr($url, 0, 1000);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $url) === 1) {
            return mb_substr($url, 0, 1000);
        }
        throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.navigation.application.navigationmanager.url_maie_buty_vnutrishnim_shliakhom_abo_https_posyla'));
    }

    /** @param array<string,mixed> $row */
    private function resolveUrl(int $storeId, string $locale, array $row): string
    {
        $type = (string) $row['item_type'];
        $ref = (string) ($row['target_ref'] ?? '');
        if ($type === 'custom') {
            return (string) ($row['url'] ?? '');
        }
        if ($type === 'page') {
            return $ref !== '' ? '/' . ltrim($ref, '/') : '';
        }
        if (in_array($type, ['category', 'product'], true) && $ref !== '' && Uuid::isValid($ref)) {
            $binary = Uuid::fromString($ref)->toBinary();
            // The visitor's language first, then the store's default language (content falls back to it on the storefront too).
            $path = $this->db->fetchOne(
                'SELECT path FROM mc_seo_route WHERE store_id=? AND entity_type=? AND entity_public_id=? AND locale IN (?, (SELECT default_locale FROM mc_store WHERE id=?)) ORDER BY (locale=?) DESC LIMIT 1',
                [$storeId, $type, $binary, $locale, $storeId, $locale],
            );

            return $path !== false ? '/' . ltrim((string) $path, '/') : '';
        }

        return '';
    }
}
