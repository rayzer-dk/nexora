<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use RuntimeException;

final class LayoutSchemaValidator
{
    public function __construct(private readonly ?ExtensionContributionRegistry $extensions = null)
    {
    }

    private const ALLOWED_COMPONENTS = [
        'hero','category_grid','product_grid','product_carousel','banner','rich_text','image','gallery','video',
        'brands','faq','newsletter','article_grid','breadcrumbs','product_gallery','product_title','product_price',
        'product_stock','product_variants','product_buy','product_description','product_attributes','product_documents',
        'product_reviews','related_products','info_card','checkout_contact','checkout_shipping','checkout_company','checkout_comment','checkout_payment','checkout_coupon','checkout_summary','checkout_consent','category_heading','category_filters','category_toolbar','category_recommended','category_grid','category_description','cart_heading','cart_lines','cart_summary','cart_saved','cart_recent','logo','search','catalog_button','menu','language','currency','account','wishlist','cart','contacts','social_links','button',
    ];

    /** @param array<string,mixed> $layout @return array<string,mixed> */
    public function validate(array $layout, ?string $layoutType = null): array
    {
        if ((int) ($layout['schema_version'] ?? 0) !== 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0f7ba0765cf6'));
        }
        $blocks = $layout['blocks'] ?? null;
        if (!is_array($blocks) || count($blocks) > 200) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.cf46a0194e37'));
        }
        $ids = [];
        $normalized = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.4722ac05ff51'));
            }
            $id = (string) ($block['id'] ?? '');
            $component = (string) ($block['component'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1 || isset($ids[$id])) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.06c9736a2502'));
            }
            $knownExtension = $this->extensions?->hasBuilderComponent($component, $layoutType) ?? false;
            $missingExtension = str_starts_with($component, 'extension.') && preg_match('/^extension\.[a-z0-9_]+\.[a-z][a-z0-9_.-]{0,95}$/D', $component) === 1 && !$knownExtension;
            $allowedBuiltIn = in_array($component, self::ALLOWED_COMPONENTS, true)
                && ($layoutType === null || in_array($component, $this->allowedForType($layoutType), true));
            if (!$allowedBuiltIn && !$knownExtension && !$missingExtension) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f55134ecaa1c') . $component);
            }
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $style = is_array($block['style'] ?? null) ? $block['style'] : [];
            $visibility = is_array($block['visibility'] ?? null) ? $block['visibility'] : [];
            if (count($props) > 64 || count($style) > 32 || count($visibility) > 16) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2dcea6f9dc7a'));
            }
            $normalized[] = [
                'id' => $id,
                'component' => $component,
                'enabled' => (bool) ($block['enabled'] ?? true),
                'props' => $this->sanitizeMap($props),
                'style' => $this->sanitizeMap($style),
                'visibility' => $this->sanitizeMap($visibility),
                'missing_extension' => $missingExtension,
            ];
            $ids[$id] = true;
        }
        return ['schema_version' => 1, 'blocks' => $normalized];
    }

    /** @return list<string> */
    private function allowedForType(string $layoutType): array
    {
        return match ($layoutType) {
            'home' => ['hero','category_grid','product_grid','article_grid'],
            'header' => ['logo','search','catalog_button','menu','language','currency','account','wishlist','cart','button'],
            'footer' => ['logo','menu','contacts','social_links','newsletter','button'],
            'product' => ['product_gallery','product_title','product_price','product_stock','product_variants','product_buy','product_description','product_attributes','product_documents','product_reviews','related_products','rich_text','image','info_card'],
            'category' => ['category_heading','category_filters','category_toolbar','category_recommended','category_grid','category_description'],
            'cart' => ['cart_heading','cart_lines','cart_summary','cart_saved','cart_recent'],
            'checkout' => ['checkout_contact','checkout_shipping','checkout_company','checkout_comment','checkout_payment','checkout_coupon','checkout_summary','checkout_consent'],
            default => [],
        };
    }

    /** @param array<mixed> $map @return array<string,mixed> */
    private function sanitizeMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $key) !== 1) {
                continue;
            }
            if (is_string($value)) {
                $out[$key] = mb_substr(strip_tags($value), 0, 4000, 'UTF-8');
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $out[$key] = $value;
            } elseif (is_array($value) && count($value) <= 50) {
                $out[$key] = $this->sanitizeNested($value, 0);
            }
        }
        return $out;
    }

    private function sanitizeNested(array $value, int $depth): array
    {
        if ($depth >= 4) {
            return [];
        }
        $out = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 50) {
                break;
            }
            $safeKey = is_int($key) ? $key : (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/Di', (string) $key) === 1 ? (string) $key : null);
            if ($safeKey === null) {
                continue;
            }
            if (is_string($item)) {
                $out[$safeKey] = mb_substr(strip_tags($item), 0, 4000, 'UTF-8');
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $out[$safeKey] = $item;
            } elseif (is_array($item)) {
                $out[$safeKey] = $this->sanitizeNested($item, $depth + 1);
            }
        }
        return $out;
    }
}

