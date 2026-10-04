<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * Parent/child links of the active categories of a store, loaded once per request. A category page shows the products of
 * the whole branch (the category and every level below it), and the storefront needs the ancestors for breadcrumbs.
 */
final class CategoryTreeIndex
{
    private const MAX_DEPTH = 12;

    /** @var array<int,array<int,int|null>> storeId => [categoryId => parentId] */
    private array $parents = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<int> the category itself and all its active descendants, never empty */
    public function scope(int $categoryId, int $storeId): array
    {
        $children = [];
        foreach ($this->load($storeId) as $id => $parent) {
            if ($parent !== null) {
                $children[$parent][] = $id;
            }
        }
        $out = [$categoryId];
        $frontier = [$categoryId];
        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; ++$depth) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($children[$id] ?? [] as $child) {
                    if (!in_array($child, $out, true)) {
                        $out[] = $child;
                        $next[] = $child;
                    }
                }
            }
            $frontier = $next;
        }

        return $out;
    }

    /** @return list<int> ancestors from the top level down to the direct parent */
    public function ancestors(int $categoryId, int $storeId): array
    {
        $map = $this->load($storeId);
        $chain = [];
        $current = $map[$categoryId] ?? null;
        for ($depth = 0; $depth < self::MAX_DEPTH && $current !== null && !in_array($current, $chain, true); ++$depth) {
            $chain[] = $current;
            $current = $map[$current] ?? null;
        }

        return array_reverse($chain);
    }

    /** @return array<int,int|null> */
    private function load(int $storeId): array
    {
        if (!isset($this->parents[$storeId])) {
            $map = [];
            foreach ($this->db->fetchAllAssociative(
                "SELECT c.id,c.parent_id FROM mc_category c JOIN mc_store_category sc ON sc.category_id=c.id AND sc.store_id=? AND sc.status='active' WHERE c.status='active'",
                [$storeId],
            ) as $row) {
                $map[(int) $row['id']] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            }
            $this->parents[$storeId] = $map;
        }

        return $this->parents[$storeId];
    }
}
