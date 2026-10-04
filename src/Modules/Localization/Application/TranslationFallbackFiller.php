<?php

declare(strict_types=1);

namespace Commerce\Modules\Localization\Application;

use Doctrine\DBAL\Connection;

/**
 * A language that is switched on but has no translation of a product, category or brand must not make it disappear from the
 * shop. The text of the store default language is copied into such a language (and marked is_fallback=1, so it follows the
 * default text on every later edit until someone translates it; saving a real translation clears the mark). The page
 * address is copied as well, so the item has a working URL in that language.
 */
final readonly class TranslationFallbackFiller
{
    private const ROUTE_TYPES = ['product' => 'mc_product', 'category' => 'mc_category', 'brand' => 'mc_brand'];

    public function __construct(private Connection $db)
    {
    }

    /** One product, after it was saved in the default language. */
    public function fillProduct(Connection $db, int $storeId, int $productId): void
    {
        foreach ($this->targets($db, $storeId) as [$source, $target]) {
            $this->copyProducts($db, $storeId, $source, $target, $productId);
            $this->copyRoutes($db, $storeId, $source, $target, 'product', 'mc_product', $productId);
        }
    }

    public function fillCategory(Connection $db, int $storeId, int $categoryId): void
    {
        foreach ($this->targets($db, $storeId) as [$source, $target]) {
            $this->copyCategories($db, $storeId, $source, $target, $categoryId);
            $this->copyRoutes($db, $storeId, $source, $target, 'category', 'mc_category', $categoryId);
        }
    }

    /** Everything of the store, for one language that was just switched on (or any time as a repair). @return array<string,int> */
    public function backfillLocale(int $storeId, string $target): array
    {
        $source = $this->defaultLocale($this->db, $storeId);
        if ($source === null || $source === $target) {
            return [];
        }

        return $this->db->transactional(function (Connection $db) use ($storeId, $source, $target): array {
            $done = [
                'products' => $this->copyProducts($db, $storeId, $source, $target, null),
                'categories' => $this->copyCategories($db, $storeId, $source, $target, null),
                'brands' => $this->copyBrands($db, $storeId, $source, $target),
                'content' => $this->copyContent($db, $storeId, $source, $target),
            ];
            foreach (self::ROUTE_TYPES as $type => $table) {
                $done['routes_' . $type] = $this->copyRoutes($db, $storeId, $source, $target, $type, $table, null);
            }
            $done['routes_content'] = $this->copyContentRoutes($db, $storeId, $source, $target);

            return $done;
        });
    }

    /** @return list<array{0:string,1:string}> pairs [default locale, other enabled locale] */
    private function targets(Connection $db, int $storeId): array
    {
        $source = $this->defaultLocale($db, $storeId);
        if ($source === null) {
            return [];
        }
        $pairs = [];
        foreach ($db->fetchFirstColumn('SELECT locale_code FROM mc_store_locale WHERE store_id=? AND enabled=1 AND locale_code<>?', [$storeId, $source]) as $locale) {
            $pairs[] = [$source, (string) $locale];
        }

        return $pairs;
    }

    private function defaultLocale(Connection $db, int $storeId): ?string
    {
        $locale = $db->fetchOne('SELECT default_locale FROM mc_store WHERE id=?', [$storeId]);

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    private function copyProducts(Connection $db, int $storeId, string $source, string $target, ?int $only): int
    {
        $scope = $only !== null ? ' AND s.product_id=' . (int) $only : '';
        // Refresh the copies that nobody translated yet, then add the missing ones.
        $db->executeStatement(
            "UPDATE mc_product_translation t JOIN mc_product_translation s ON s.product_id=t.product_id AND s.store_id=t.store_id AND s.locale=?
             SET t.name=s.name,t.short_description=s.short_description,t.description=s.description,t.meta_title=s.meta_title,t.meta_description=s.meta_description,t.updated_at=s.updated_at
             WHERE t.store_id=? AND t.locale=? AND t.is_fallback=1" . str_replace('s.product_id', 't.product_id', $scope),
            [$source, $storeId, $target],
        );

        return (int) $db->executeStatement(
            "INSERT INTO mc_product_translation (product_id,store_id,locale,name,slug,short_description,description,meta_title,meta_description,created_at,updated_at,is_fallback)
             SELECT s.product_id,s.store_id,?,s.name,NULL,s.short_description,s.description,s.meta_title,s.meta_description,s.created_at,s.updated_at,1
             FROM mc_product_translation s WHERE s.store_id=? AND s.locale=?{$scope}
               AND NOT EXISTS (SELECT 1 FROM mc_product_translation t WHERE t.product_id=s.product_id AND t.store_id=s.store_id AND t.locale=?)",
            [$target, $storeId, $source, $target],
        );
    }

    private function copyCategories(Connection $db, int $storeId, string $source, string $target, ?int $only): int
    {
        $scope = $only !== null ? ' AND s.category_id=' . (int) $only : '';
        $db->executeStatement(
            "UPDATE mc_category_translation t JOIN mc_category_translation s ON s.category_id=t.category_id AND s.store_id=t.store_id AND s.locale=?
             SET t.name=s.name,t.description=s.description,t.description_bottom=s.description_bottom,t.meta_title=s.meta_title,t.meta_description=s.meta_description
             WHERE t.store_id=? AND t.locale=? AND t.is_fallback=1" . str_replace('s.category_id', 't.category_id', $scope),
            [$source, $storeId, $target],
        );

        return (int) $db->executeStatement(
            "INSERT INTO mc_category_translation (category_id,store_id,locale,name,slug,description,meta_title,meta_description,description_bottom,is_fallback)
             SELECT s.category_id,s.store_id,?,s.name,NULL,s.description,s.meta_title,s.meta_description,s.description_bottom,1
             FROM mc_category_translation s WHERE s.store_id=? AND s.locale=?{$scope}
               AND NOT EXISTS (SELECT 1 FROM mc_category_translation t WHERE t.category_id=s.category_id AND t.store_id=s.store_id AND t.locale=?)",
            [$target, $storeId, $source, $target],
        );
    }

    private function copyBrands(Connection $db, int $storeId, string $source, string $target): int
    {
        return (int) $db->executeStatement(
            'INSERT INTO mc_brand_translation (brand_id,store_id,locale,slug,description,meta_title,meta_description,is_fallback)
             SELECT s.brand_id,s.store_id,?,s.slug,s.description,s.meta_title,s.meta_description,1
             FROM mc_brand_translation s WHERE s.store_id=? AND s.locale=?
               AND NOT EXISTS (SELECT 1 FROM mc_brand_translation t WHERE t.brand_id=s.brand_id AND t.store_id=s.store_id AND t.locale=?)',
            [$target, $storeId, $source, $target],
        );
    }

    /** Pages and articles: a copy of the default text, so lists and menus keep showing them. */
    private function copyContent(Connection $db, int $storeId, string $source, string $target): int
    {
        return (int) $db->executeStatement(
            'INSERT INTO mc_content_translation (content_id,locale,title,excerpt,body_html,meta_title,meta_description,created_at,updated_at)
             SELECT s.content_id,?,s.title,s.excerpt,s.body_html,s.meta_title,s.meta_description,s.created_at,s.updated_at
             FROM mc_content_translation s JOIN mc_content_entry ce ON ce.id=s.content_id AND ce.store_id=? WHERE s.locale=?
               AND NOT EXISTS (SELECT 1 FROM mc_content_translation t WHERE t.content_id=s.content_id AND t.locale=?)',
            [$target, $storeId, $source, $target],
        );
    }

    private function copyRoutes(Connection $db, int $storeId, string $source, string $target, string $type, string $table, ?int $only): int
    {
        $scope = $only !== null ? ' AND r.entity_public_id=(SELECT public_id FROM ' . $table . ' WHERE id=' . (int) $only . ')' : '';

        return $this->insertRoutes($db, $storeId, $source, $target, $type, $scope);
    }

    private function copyContentRoutes(Connection $db, int $storeId, string $source, string $target): int
    {
        return $this->insertRoutes($db, $storeId, $source, $target, null, " AND r.entity_type IN ('blog_article','cms_page')");
    }

    private function insertRoutes(Connection $db, int $storeId, string $source, string $target, ?string $type, string $scope): int
    {
        $typeSql = $type !== null ? ' AND r.entity_type=' . $db->quote($type) : '';
        $now = gmdate('Y-m-d H:i:s.u');

        return (int) $db->executeStatement(
            "INSERT INTO mc_seo_route (public_id,store_id,locale,entity_type,entity_public_id,slug,path,path_hash,indexable,created_at,updated_at)
             SELECT UNHEX(REPLACE(UUID(),'-','')),r.store_id,?,r.entity_type,r.entity_public_id,r.slug,r.path,r.path_hash,r.indexable,?,?
             FROM mc_seo_route r WHERE r.store_id=? AND r.locale=?{$typeSql}{$scope}
               AND NOT EXISTS (SELECT 1 FROM mc_seo_route x WHERE x.store_id=r.store_id AND x.locale=? AND x.entity_type=r.entity_type AND x.entity_public_id=r.entity_public_id)
               AND NOT EXISTS (SELECT 1 FROM mc_seo_route y WHERE y.store_id=r.store_id AND y.locale=? AND y.path_hash=r.path_hash)",
            [$target, $now, $now, $storeId, $source, $target, $target],
        );
    }
}
