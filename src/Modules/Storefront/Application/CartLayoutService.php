<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Application;

use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;

/**
 * Cart page layout from the Cart Builder: the heading and the two sections under the cart (saved carts, recently
 * viewed) can be hidden per device and ordered. The lines and the summary with the checkout button are never
 * removable; nothing published means the built-in page.
 */
final readonly class CartLayoutService
{
    private const BELOW = ['cart_saved' => 'saved', 'cart_recent' => 'recent'];

    public function __construct(private LayoutRevisionStore $layouts)
    {
    }

    /** @return array{below:list<string>,show:array<string,bool>,classes:array<string,string>} */
    public function active(int $storeId): array
    {
        $layout = $this->layouts->publishedOrNull($storeId, 'cart');
        $rows = is_array($layout) ? (array) ($layout['blocks'] ?? []) : [];
        $show = ['heading' => true, 'saved' => true, 'recent' => true];
        $classes = array_fill_keys(array_keys($show), '');
        $below = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $component = (string) ($row['component'] ?? '');
            $name = $component === 'cart_heading' ? 'heading' : (self::BELOW[$component] ?? null);
            if ($name === null || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $show[$name] = ($row['enabled'] ?? true) !== false;
            $visibility = is_array($row['visibility'] ?? null) ? $row['visibility'] : [];
            foreach (['desktop', 'tablet', 'mobile'] as $device) {
                if (($visibility[$device] ?? true) === false) {
                    $classes[$name] .= ' is-hidden-' . $device;
                }
            }
            if ($name !== 'heading' && $show[$name]) {
                $below[] = $name;
            }
        }
        foreach (self::BELOW as $name) {
            if (!isset($seen[$name])) {
                $below[] = $name;
            }
        }

        return ['below' => $below, 'show' => $show, 'classes' => array_map('trim', $classes)];
    }
}
