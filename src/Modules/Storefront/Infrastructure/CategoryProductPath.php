<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Infrastructure;

use Commerce\Modules\Seo\Application\SeoRouteResolver;
use Commerce\Modules\Seo\Domain\SeoEntityType;
use Commerce\Modules\Seo\Domain\SeoRoute;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Optional product addresses with the category path (/electronics/phones/iphone-13). The path is only an alias of the
 * flat product address, which stays canonical: a path whose categories do not lead to the product is simply not found.
 */
final class CategoryProductPath
{
    public function __construct(private readonly Connection $db, private readonly SeoRouteResolver $resolver, private readonly CategoryTreeIndex $tree)
    {
    }

    /** The product route a "category/…/product" path points to, or null when it is not such a path. */
    public function productRoute(int $storeId, string $locale, string $path): ?SeoRoute
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
        if (count($segments) < 2 || count($segments) > 8) {
            return null;
        }
        $productSlug = (string) array_pop($segments);
        $resolved = $this->resolver->resolve($storeId, $locale, $productSlug);
        $route = $resolved->route;
        if ($route === null || $resolved->isRedirect() || $route->entityType !== SeoEntityType::Product) {
            return null;
        }
        $previous = null;
        foreach ($segments as $slug) {
            $category = $this->resolver->resolve($storeId, $locale, $slug);
            if ($category->route === null || $category->isRedirect() || $category->route->entityType !== SeoEntityType::Category) {
                return null;
            }
            $row = $this->db->fetchAssociative('SELECT id,parent_id FROM mc_category WHERE public_id=?', [Uuid::fromString($category->route->entityPublicId)->toBinary()]);
            if (!is_array($row)) {
                return null;
            }
            $parent = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            if ($parent !== $previous) {
                return null;
            }
            $previous = (int) $row['id'];
        }
        if ($previous === null) {
            return null;
        }
        $own = array_map('intval', $this->db->fetchFirstColumn(
            'SELECT pc.category_id FROM mc_product_category pc JOIN mc_product p ON p.id=pc.product_id WHERE p.public_id=?',
            [Uuid::fromString($route->entityPublicId)->toBinary()],
        ));
        foreach ($own as $categoryId) {
            if ($categoryId === $previous || in_array($previous, $this->tree->ancestors($categoryId, $storeId), true)) {
                return $route;
            }
        }

        return null;
    }
}
