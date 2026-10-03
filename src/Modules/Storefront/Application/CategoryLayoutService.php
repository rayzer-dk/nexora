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
    private const MAIN = ['category_toolbar' => 'toolbar', 'category_recommended' => 'recommended', 'category_grid' => 'grid'];
    private const SIDE = ['category_heading' => 'heading', 'category_filters' => 'filters', 'category_description' => 'description'];

    public function __construct(private LayoutRevisionStore $layouts)
    {
    }

    /** @return array{main:list<string>,show:array<string,bool>,classes:array<string,string>} */
    public function active(int $storeId): array
    {
        $layout = $this->layouts->publishedOrNull($storeId, 'category');
        $rows = is_array($layout) ? (array) ($layout['blocks'] ?? []) : [];
        $main = [];
        $show = ['heading' => true, 'filters' => true, 'description' => true, 'toolbar' => true, 'recommended' => true, 'grid' => true];
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
            if (in_array($name, self::MAIN, true) && $enabled) {
                $main[] = $name;
            }
        }
        foreach (self::MAIN as $name) {
            if (!isset($seen[$name])) {
                $main[] = $name;
            }
        }

        return ['main' => $main, 'show' => $show, 'classes' => array_map('trim', $classes)];
    }
}
