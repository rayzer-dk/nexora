<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Application;

use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;

/**
 * Category page layout from the Category Builder: which sections show, on which devices, and in what order the
 * main column is stacked. Nothing published means the built-in layout, so a store never loses its product list.
 */
final readonly class CategoryLayoutService
{
    private const MAIN = ['category_subcategories' => 'subcategories', 'category_toolbar' => 'toolbar', 'category_recommended' => 'recommended', 'category_grid' => 'grid'];
    private const SIDE = ['category_heading' => 'heading', 'category_filters' => 'filters', 'category_description' => 'description'];

    public function __construct(private LayoutRevisionStore $layouts)
    {
    }

    /** @return array{main:list<string>,show:array<string,bool>,classes:array<string,string>,subcategories:array{layout:string,columns:int,show_image:bool,show_count:bool,limit:int,order:string}} */
    public function active(int $storeId): array
    {
        $layout = $this->layouts->publishedOrNull($storeId, 'category');
        $rows = is_array($layout) ? (array) ($layout['blocks'] ?? []) : [];
        $main = [];
        $show = ['heading' => true, 'filters' => true, 'description' => true, 'toolbar' => true, 'recommended' => true, 'grid' => true, 'subcategories' => true];
        $subcategories = ['layout' => 'grid', 'columns' => 4, 'show_image' => true, 'show_count' => true, 'limit' => 24, 'order' => 'manual'];
        $classes = array_fill_keys(array_keys($show), '');
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $component = (string) ($row['component'] ?? '');
            $name = self::MAIN[$component] ?? self::SIDE[$component] ?? null;
            if ($name === null || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            // The product grid is what a category page is for: it cannot be switched off.
            $enabled = $name === 'grid' || ($row['enabled'] ?? true) !== false;
            $show[$name] = $enabled;
            $visibility = is_array($row['visibility'] ?? null) ? $row['visibility'] : [];
            foreach (['desktop', 'tablet', 'mobile'] as $device) {
                // The filter panel is also the mobile bottom sheet, so only its on/off switch applies to it.
                if (!in_array($name, ['grid', 'filters'], true) && ($visibility[$device] ?? true) === false) {
                    $classes[$name] .= ' is-hidden-' . $device;
                }
            }
            if ($name === 'subcategories') {
                $props = is_array($row['props'] ?? null) ? $row['props'] : [];
                $subcategories = [
                    'layout' => ($props['layout'] ?? 'grid') === 'scroll' ? 'scroll' : 'grid',
                    'columns' => max(2, min(6, (int) ($props['columns'] ?? 4) ?: 4)),
                    'show_image' => ($props['show_image'] ?? true) !== false && ($props['show_image'] ?? true) !== '0',
                    'show_count' => ($props['show_count'] ?? true) !== false && ($props['show_count'] ?? true) !== '0',
                    'limit' => max(1, min(60, (int) ($props['limit'] ?? 24) ?: 24)),
                    'order' => in_array((string) ($props['order'] ?? 'manual'), ['manual', 'name', 'popular', 'newest'], true) ? (string) ($props['order'] ?? 'manual') : 'manual',
                ];
            }
            if (in_array($name, self::MAIN, true) && $enabled) {
                $main[] = $name;
            }
        }
        foreach (self::MAIN as $name) {
            if (!isset($seen[$name])) {
                // A layout saved before the subcategory tiles existed gets them above the product list.
                $name === 'subcategories' ? array_unshift($main, $name) : $main[] = $name;
            }
        }

        return ['main' => $main, 'show' => $show, 'classes' => array_map('trim', $classes), 'subcategories' => $subcategories];
    }
}
