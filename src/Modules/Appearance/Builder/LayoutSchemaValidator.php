<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\Storefront\Twig\HomeBlockExtension;
use RuntimeException;

final class LayoutSchemaValidator
{
    public function __construct(
        private readonly ?ExtensionContributionRegistry $extensions = null,
        private readonly BuilderComponentCatalog $catalog = new BuilderComponentCatalog(),
        private readonly HomeBlockExtension $blockStyle = new HomeBlockExtension(),
    ) {
    }

    private const ALLOWED_COMPONENTS = [
        'hero','category_grid','product_grid','product_carousel','recently_viewed','banner','scroll_story','video_hero','rich_text','image','gallery','video',
        'brands','faq','newsletter','article_grid','breadcrumbs','product_gallery','product_title','product_price',
        'product_stock','product_variants','product_buy','product_description','product_attributes','product_documents',
        'product_reviews','related_products','info_card','checkout_contact','checkout_shipping','checkout_address','checkout_company','checkout_comment','checkout_payment','checkout_coupon','checkout_summary','checkout_consent','category_heading','category_filters','category_subcategories','category_toolbar','category_recommended','category_grid','category_description','cart_heading','cart_lines','cart_summary','cart_saved','cart_recent','logo','search','catalog_button','menu','language','currency','account','wishlist','cart','contacts','social_links','button',
    ];

    /**
     * Values of described fields (BuilderComponentCatalog, the block schema of a module) are checked too: a save with
     * $rejectInvalidFields throws on the first wrong one; reading drops it and pulls a number back into its range.
     *
     * @param array<string,mixed> $layout @return array<string,mixed>
     */
    public function validate(array $layout, ?string $layoutType = null, bool $rejectInvalidFields = false): array
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
                && ($layoutType === null || in_array($component, self::allowedForType($layoutType), true));
            if (!$allowedBuiltIn && !$knownExtension && !$missingExtension) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f55134ecaa1c') . $component);
            }
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $style = is_array($block['style'] ?? null) ? $block['style'] : [];
            $visibility = is_array($block['visibility'] ?? null) ? $block['visibility'] : [];
            if (count($props) > 64 || count($style) > 32 || count($visibility) > 16) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.2dcea6f9dc7a'));
            }
            $normalized[] = $this->checkFields([
                'id' => $id,
                'component' => $component,
                'enabled' => (bool) ($block['enabled'] ?? true),
                'props' => $this->sanitizeMap($props),
                'style' => $this->sanitizeMap($style),
                'visibility' => $this->sanitizeMap($visibility),
                'missing_extension' => $missingExtension,
            ], $layoutType, $knownExtension, $rejectInvalidFields);
            $ids[$id] = true;
        }
        return ['schema_version' => 1, 'blocks' => $normalized];
    }

    /** @return list<string> */
    public static function allowedForType(string $layoutType): array
    {
        return match ($layoutType) {
            'home' => ['hero','banner','scroll_story','video_hero','category_grid','product_grid','recently_viewed','article_grid'],
            'header' => ['logo','search','catalog_button','menu','language','currency','account','wishlist','cart','button'],
            'footer' => ['logo','menu','contacts','social_links','newsletter','button'],
            'product' => ['product_gallery','product_title','product_price','product_stock','product_variants','product_buy','product_description','product_attributes','product_documents','product_reviews','related_products','rich_text','image','info_card'],
            'category' => ['category_heading','category_filters','category_subcategories','category_toolbar','category_recommended','category_grid','category_description'],
            'cart' => ['cart_heading','cart_lines','cart_summary','cart_saved','cart_recent'],
            'checkout' => ['checkout_contact','checkout_shipping','checkout_address','checkout_company','checkout_comment','checkout_payment','checkout_coupon','checkout_summary','checkout_consent'],
            default => [],
        };
    }

    /** @param array{id:string,component:string,enabled:bool,props:array<string,mixed>,style:array<string,mixed>,visibility:array<string,mixed>,missing_extension:bool} $block @return array<string,mixed> */
    private function checkFields(array $block, ?string $layoutType, bool $extension, bool $reject): array
    {
        if ($layoutType === null) {
            return $block;
        }
        // A module's schema is parsed only when saving: storefront pages read layouts on every request.
        $fields = $extension ? ($reject ? $this->extensions?->blockFields($block['component']) : null) : $this->catalog->fieldsFor($layoutType, $block['component']);
        foreach ($fields ?? [] as $field) {
            $path = explode('.', (string) $field['prop'], 2);
            if (count($path) !== 2 || !in_array($path[0], ['props', 'style', 'visibility'], true) || !array_key_exists($path[1], $block[$path[0]])) {
                continue;
            }
            [$group, $key] = $path;
            [$valid, $value] = $this->fieldValue($field, $block[$group][$key], $reject);
            if ($valid) {
                $block[$group][$key] = $value;
            } elseif ($reject) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('admin.builder.invalid_field', ['block' => $block['id'], 'field' => (string) $field['prop']]));
            } else {
                unset($block[$group][$key]);
            }
        }

        return $block;
    }

    /** @param array<string,mixed> $field @return array{0:bool,1:mixed} whether the value fits the field, and the value to keep */
    private function fieldValue(array $field, mixed $value, bool $reject): array
    {
        if ($value === null || $value === '') {
            return [true, $value];
        }

        return match ((string) $field['type']) {
            'url', 'image' => [is_string($value) && self::isSafeLink($value), $value],
            'color' => [is_string($value) && $this->blockStyle->background($value) === $value, $value],
            'boolean' => [in_array($value, [true, false, 0, 1, '0', '1'], true), $value],
            'select' => [is_scalar($value) && !is_bool($value) && in_array((string) $value, array_map(static fn (array $option): string => (string) $option['value'], (array) ($field['options'] ?? [])), true), $value],
            'number' => $this->numberValue($field, $value, $reject),
            'multilingual_text', 'multilingual_textarea' => [is_string($value) || (is_array($value) && self::isLocalizedMap($value)), $value],
            default => [true, $value],
        };
    }

    /** @param array<string,mixed> $field @return array{0:bool,1:mixed} */
    private function numberValue(array $field, mixed $value, bool $reject): array
    {
        if (is_bool($value) || !is_numeric($value)) {
            return [false, $value];
        }
        $number = (float) $value;
        $min = isset($field['min']) ? (float) $field['min'] : -INF;
        $max = isset($field['max']) ? (float) $field['max'] : INF;
        if ($number >= $min && $number <= $max) {
            return [true, $value];
        }
        if ($reject) {
            return [false, $value];
        }
        $clamped = max($min, min($max, $number));

        return [true, floor($clamped) === $clamped ? (int) $clamped : $clamped];
    }

    /** A relative path or anchor, or an absolute http(s), mailto or tel link: no script or data URL, no spaces or quotes. */
    private static function isSafeLink(string $value): bool
    {
        // A phone number is usually written with spaces, so a plain space is allowed in tel: links only.
        $forbidden = stripos($value, 'tel:') === 0 ? '/[^\S ]|["\'<>\\\\\x00-\x1f]/u' : '/[\s"\'<>\\\\\x00-\x1f]/u';
        if (strlen($value) > 2000 || preg_match($forbidden, $value) === 1) {
            return false;
        }

        return preg_match('/^([a-z][a-z0-9+.-]*):/i', $value, $scheme) !== 1 || in_array(strtolower($scheme[1]), ['http', 'https', 'mailto', 'tel'], true);
    }

    /** @param array<mixed> $value */
    private static function isLocalizedMap(array $value): bool
    {
        foreach ($value as $locale => $text) {
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $locale) !== 1 || !is_scalar($text)) {
                return false;
            }
        }

        return true;
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

