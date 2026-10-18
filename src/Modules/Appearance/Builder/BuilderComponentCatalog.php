<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

/**
 * Field descriptions of the built-in builder blocks: the properties panel draws its controls from them and
 * LayoutSchemaValidator checks saved values against them. Same field format as the `settings_schema` of modules,
 * plus `prop` (the path inside the block), `label_key` and an optional `hint_key`.
 *
 * @phpstan-type Field array{key:string,type:string,label_key:string,group:string,prop:string,default?:mixed,options?:list<array{value:string,label_key:string}>,min?:int|float,max?:int|float,step?:int|float,hint_key?:string,widget?:string}
 */
final class BuilderComponentCatalog
{
    /** Add-block list of every page type, in the order it is offered: component => label key. */
    private const LABELS = [
        'home' => ['banner' => 'admin.builder.banner', 'scroll_story' => 'admin.builder.scroll_story', 'video_hero' => 'admin.builder.video_hero', 'hero' => 'ui.admin.appearance.builder.hero', 'category_grid' => 'ui.admin.appearance.builder.category_grid', 'product_grid' => 'ui.admin.appearance.builder.product_grid', 'recently_viewed' => 'admin.builder.recently_viewed', 'article_grid' => 'ui.admin.appearance.builder.article_grid'],
        'product' => ['product_gallery' => 'admin.appearance.builder.halereia', 'product_title' => 'admin.appearance.builder.nazva', 'product_price' => 'admin.appearance.builder.tsina', 'product_stock' => 'admin.appearance.builder.naiavnist', 'product_variants' => 'admin.appearance.builder.varianty', 'product_buy' => 'admin.appearance.builder.kupivlia', 'product_description' => 'admin.appearance.builder.opys', 'product_attributes' => 'admin.appearance.builder.kharakterystyky', 'product_documents' => 'admin.appearance.builder.dokumenty', 'product_reviews' => 'admin.appearance.builder.vidhuky', 'related_products' => 'admin.appearance.builder.skhozhi_tovary', 'rich_text' => 'admin.appearance.builder.vlasnyi_tekst', 'image' => 'admin.appearance.builder.zobrazhennia', 'info_card' => 'admin.appearance.builder.info_kartka'],
        'checkout' => ['checkout_contact' => 'admin.appearance.builder.kontakt', 'checkout_shipping' => 'admin.appearance.builder.dostavka', 'checkout_address' => 'admin.builder.checkout_address', 'checkout_company' => 'admin.appearance.builder.kompaniia_fop', 'checkout_comment' => 'admin.appearance.builder.komentar', 'checkout_payment' => 'admin.appearance.builder.oplata', 'checkout_coupon' => 'admin.appearance.builder.promokod', 'checkout_summary' => 'admin.appearance.builder.pidsumok', 'checkout_consent' => 'admin.appearance.builder.pidtverdzhennia'],
        'category' => ['category_heading' => 'admin.builder.category_heading', 'category_filters' => 'admin.builder.category_filters', 'category_subcategories' => 'admin.builder.category_subcategories', 'category_toolbar' => 'admin.builder.category_toolbar', 'category_recommended' => 'admin.builder.category_recommended', 'category_grid' => 'admin.builder.category_grid', 'category_description' => 'admin.builder.category_description'],
        'cart' => ['cart_heading' => 'admin.builder.cart_heading', 'cart_lines' => 'admin.builder.cart_lines', 'cart_summary' => 'admin.builder.cart_summary', 'cart_saved' => 'admin.builder.cart_saved', 'cart_recent' => 'admin.builder.cart_recent'],
    ];

    /** Blocks the storefront draws from its own data: only the common fields (id, style, devices) apply to them. */
    private const GENERIC = [
        'home' => ['recently_viewed'],
        'product' => ['product_gallery', 'product_title', 'product_price', 'product_stock', 'product_variants', 'product_buy', 'product_description', 'product_attributes', 'product_documents', 'product_reviews', 'related_products'],
        'category' => ['category_heading', 'category_filters', 'category_toolbar', 'category_recommended', 'category_grid', 'category_description'],
        'cart' => ['cart_heading', 'cart_lines', 'cart_summary', 'cart_saved', 'cart_recent'],
    ];

    /** @return list<array{component:string,label_key:string,fields:list<Field>}> */
    public function components(string $layoutType): array
    {
        $allowed = LayoutSchemaValidator::allowedForType($layoutType);
        $out = [];
        foreach (self::LABELS[$layoutType] ?? [] as $component => $labelKey) {
            if (in_array($component, $allowed, true)) {
                $out[] = ['component' => $component, 'label_key' => $labelKey, 'fields' => $this->fieldsFor($layoutType, $component) ?? []];
            }
        }

        return $out;
    }

    /** @return list<Field>|null null when the type may not hold the component (an old or unknown block) */
    public function fieldsFor(string $layoutType, string $component): ?array
    {
        if (!in_array($component, LayoutSchemaValidator::allowedForType($layoutType), true)) {
            return null;
        }
        $own = $this->ownFields($layoutType, $component);

        return $own === null ? null : $this->withCommonFields($own);
    }

    /**
     * @param list<array<string,mixed>> $own fields of one block (also a module block)
     * @return list<array<string,mixed>> the block id first, the block's own fields, then style and devices
     */
    public function withCommonFields(array $own): array
    {
        [$block, $style] = $this->commonParts();

        return [...$block, ...$own, ...$style];
    }

    public function isGeneric(string $layoutType, string $component): bool
    {
        return in_array($component, self::GENERIC[$layoutType] ?? [], true);
    }

    /** @return list<Field> the fields every block has, also the only ones of a block without a description */
    public function commonFields(): array
    {
        [$block, $style] = $this->commonParts();

        return [...$block, ...$style];
    }

    /** @return list<Field>|null */
    private function ownFields(string $type, string $component): ?array
    {
        if ($this->isGeneric($type, $component)) {
            return [];
        }
        if ($type === 'checkout') {
            return $this->checkout($component);
        }

        return match ($type . ':' . $component) {
            'home:hero' => [$this->title(), $this->text(), $this->field('image', 'image', 'builder_image', 'builder_content'), ...$this->button()],
            'home:banner' => $this->banner(),
            'home:scroll_story' => $this->scrollStory(),
            'home:video_hero' => $this->videoHero(),
            'home:category_grid' => [$this->title(), ...$this->tiles(24, 12)],
            'home:product_grid' => [
                $this->title(),
                $this->select('display', 'builder_products_display', 'builder_products', ['grid' => 'builder_display_grid', 'carousel' => 'builder_display_carousel'], 'grid'),
                $this->select('source', 'builder_products_source', 'builder_products', ['' => 'builder_source_catalog', 'popular' => 'builder_source_popular', 'newest' => 'builder_source_newest'], '', ['hint_key' => 'builder_products_hint']),
                $this->field('limit', 'number', 'builder_limit', 'builder_products', ['min' => 1, 'max' => 24, 'step' => 1, 'default' => 10]),
            ],
            'home:article_grid' => [$this->title()],
            'product:rich_text', 'product:info_card' => [$this->title(), $this->text(), ...$this->button(), $this->dataSource()],
            'product:image' => [$this->title(), $this->text(), $this->field('image', 'image', 'builder_image', 'builder_content'), $this->field('url', 'url', 'builder_link', 'builder_content')],
            'category:category_subcategories' => $this->tiles(60, 24),
            default => null,
        };
    }

    /** @return list<Field>|null */
    private function checkout(string $component): ?array
    {
        $placement = $this->select('placement', 'builder_column', 'builder_checkout', ['left' => 'builder_column_left', 'right' => 'builder_column_right', 'full' => 'builder_full_width'], $component === 'checkout_summary' ? 'right' : 'left');
        $switch = fn (string $key, string $label, bool $default, array $extra = []): array => $this->field($key, 'boolean', $label, 'builder_checkout', ['default' => $default] + $extra);

        return match ($component) {
            'checkout_contact' => [$placement, $switch('show_name', 'builder_show_name', true), $switch('require_name', 'builder_require_name', true), $switch('show_phone', 'builder_show_phone', true), $switch('require_phone', 'builder_require_phone', true), $switch('show_email', 'builder_show_email', true), $switch('require_email', 'builder_require_email', false)],
            'checkout_shipping' => [$this->title(), $placement, $switch('show_region', 'builder_show_region', true), $switch('show_city_search', 'builder_show_city_search', true, ['hint_key' => 'builder_city_search_hint'])],
            'checkout_address' => [$this->title(), $placement, $switch('always', 'builder_address_always', false, ['hint_key' => 'builder_address_always_hint']), $switch('show_country', 'builder_address_country', true), $switch('show_postcode', 'builder_address_postcode', true)],
            'checkout_company', 'checkout_comment', 'checkout_payment', 'checkout_coupon' => [$this->title(), $placement],
            'checkout_summary' => [$this->title(), $this->field('button_label', 'text', 'builder_button_text', 'builder_content'), $placement],
            'checkout_consent' => [$placement],
            default => null,
        };
    }

    /** @return list<Field> */
    private function banner(): array
    {
        $group = 'builder_banner';

        return [
            $this->title(),
            $this->text(),
            $this->field('url', 'url', 'builder_link', 'builder_content'),
            ...$this->button(),
            $this->field('image', 'image', 'builder_banner_image', $group),
            $this->field('image_mobile', 'image', 'builder_banner_image_mobile', $group, ['hint_key' => 'builder_banner_image_hint']),
            $this->select('width', 'builder_banner_width', $group, ['full' => 'builder_width_full', 'two_thirds' => 'builder_width_two_thirds', 'half' => 'builder_width_half', 'third' => 'builder_width_third'], 'full', ['hint_key' => 'builder_banner_width_hint']),
            $this->select('height', 'builder_banner_height', $group, ['s' => 'builder_height_s', 'm' => 'builder_height_m', 'l' => 'builder_height_l', 'xl' => 'builder_height_xl'], 'm'),
            $this->position($group),
            $this->field('overlay', 'number', 'builder_overlay', $group, ['min' => 0, 'max' => 80, 'step' => 1, 'hint_key' => 'builder_overlay_hint']),
            $this->field('slide', 'boolean', 'builder_banner_slide', $group, ['default' => false, 'hint_key' => 'builder_banner_slide_hint']),
            $this->field('slide_interval', 'number', 'builder_banner_interval', $group, ['min' => 0, 'max' => 30, 'step' => 1]),
            $this->field('bleed', 'boolean', 'builder_banner_bleed', $group, ['default' => false, 'hint_key' => 'builder_banner_bleed_hint']),
        ];
    }

    /** @return list<Field> */
    private function scrollStory(): array
    {
        $fields = [
            $this->title(),
            $this->text(),
            ...$this->button(),
            $this->select('image_side', 'builder_story_side', 'builder_story', ['left' => 'builder_left', 'right' => 'builder_right'], 'left', ['hint_key' => 'builder_story_hint']),
        ];
        for ($step = 1; $step <= 4; ++$step) {
            $group = 'builder_story_step_' . $step;
            $fields[] = $this->field('step' . $step . '_title', 'text', 'builder_title', $group);
            $fields[] = $this->field('step' . $step . '_text', 'textarea', 'builder_text', $group);
            $fields[] = $this->field('step' . $step . '_image', 'image', 'builder_image', $group);
        }

        return $fields;
    }

    /** @return list<Field> */
    private function videoHero(): array
    {
        $group = 'builder_video';

        return [
            $this->title(),
            $this->text(),
            ...$this->button(),
            $this->field('video_url', 'url', 'builder_video_url', $group, ['hint_key' => 'builder_video_hint']),
            $this->field('image', 'image', 'builder_video_poster', $group),
            $this->select('height', 'builder_banner_height', $group, ['m' => 'builder_height_m', 'l' => 'builder_height_l', 'xl' => 'builder_height_xl', 'screen' => 'builder_height_screen'], 'l'),
            $this->position($group),
            $this->field('overlay', 'number', 'builder_overlay', $group, ['min' => 0, 'max' => 80, 'step' => 1]),
            $this->field('mobile_video', 'boolean', 'builder_video_mobile', $group, ['default' => false, 'hint_key' => 'builder_video_mobile_hint']),
        ];
    }

    /**
     * @param int $maxLimit the range HomeController (24) and CategoryLayoutService (60) clamp the tile count to
     * @return list<Field>
     */
    private function tiles(int $maxLimit, int $defaultLimit): array
    {
        $group = 'builder_tiles';
        $fields = [
            $this->select('layout', 'builder_tiles_layout', $group, ['grid' => 'builder_layout_grid', 'scroll' => 'builder_layout_scroll'], 'grid'),
            $this->field('columns', 'number', 'builder_columns', $group, ['min' => 2, 'max' => 6, 'step' => 1]),
            $this->field('limit', 'number', 'builder_limit', $group, ['min' => 1, 'max' => $maxLimit, 'step' => 1, 'default' => $defaultLimit]),
        ];
        $fields[] = $this->select('order', 'builder_tiles_order', $group, ['manual' => 'builder_order_manual', 'name' => 'builder_order_name', 'popular' => 'builder_order_popular', 'newest' => 'builder_order_newest'], 'manual', ['hint_key' => 'builder_tiles_order_hint']);
        $fields[] = $this->field('show_image', 'boolean', 'builder_show_image', $group, ['default' => true]);
        $fields[] = $this->field('show_count', 'boolean', 'builder_show_count', $group, ['default' => true]);

        return $fields;
    }

    /** @return Field */
    private function dataSource(): array
    {
        return $this->select('data_source', 'builder_dynamic_source', 'builder_content', ['' => 'builder_own_text', 'product.name' => 'builder_product_name', 'product.brand' => 'builder_brand', 'product.sku' => 'builder_sku', 'product.gtin' => 'builder_gtin', 'product.mpn' => 'builder_mpn', 'product.price' => 'builder_price', 'product.availability' => 'builder_availability'], '', ['hint_key' => 'builder_dynamic_hint']);
    }

    /** @return Field */
    private function position(string $group): array
    {
        $options = [];
        foreach (['tl', 'tc', 'tr', 'ml', 'mc', 'mr', 'bl', 'bc', 'br'] as $cell) {
            $options[$cell] = 'builder_pos.' . $cell;
        }

        return $this->select('position', 'builder_text_position', $group, $options, 'ml', ['widget' => 'position_grid']);
    }

    /** @return Field */
    private function title(): array
    {
        return $this->field('title', 'text', 'builder_title', 'builder_content');
    }

    /** @return Field */
    private function text(): array
    {
        return $this->field('text', 'textarea', 'builder_text', 'builder_content');
    }

    /** @return list<Field> */
    private function button(): array
    {
        return [$this->field('button_label', 'text', 'builder_button_text', 'builder_content'), $this->field('button_url', 'url', 'builder_button_url', 'builder_content')];
    }

    /** @return array{0:list<Field>,1:list<Field>} the leading (id) and trailing (style, devices) common fields */
    private function commonParts(): array
    {
        $style = 'builder_style';
        $devices = 'builder_responsive';

        return [
            [['key' => 'id', 'type' => 'text', 'label_key' => 'builder_block_id', 'group' => 'builder_block', 'prop' => 'id']],
            [
                $this->field('background', 'color', 'builder_background', $style, ['prop' => 'style.background']),
                $this->field('color', 'color', 'builder_text_color', $style, ['prop' => 'style.color']),
                $this->field('padding', 'text', 'builder_padding', $style, ['prop' => 'style.padding']),
                $this->field('margin', 'text', 'builder_margin', $style, ['prop' => 'style.margin']),
                $this->select('text_align', 'builder_alignment', $style, ['left' => 'builder_left', 'center' => 'builder_center', 'right' => 'builder_right'], 'left', ['prop' => 'style.text_align']),
                $this->field('desktop', 'boolean', 'builder_desktop', $devices, ['default' => true, 'prop' => 'visibility.desktop']),
                $this->field('tablet', 'boolean', 'builder_tablet', $devices, ['default' => true, 'prop' => 'visibility.tablet']),
                $this->field('mobile', 'boolean', 'builder_mobile', $devices, ['default' => true, 'prop' => 'visibility.mobile']),
            ],
        ];
    }

    /** @param array<string,string> $options value => label key @param array<string,mixed> $extra @return Field */
    private function select(string $key, string $labelKey, string $group, array $options, string $default, array $extra = []): array
    {
        $list = [];
        foreach ($options as $value => $optionLabel) {
            $list[] = ['value' => (string) $value, 'label_key' => $optionLabel];
        }

        return $this->field($key, 'select', $labelKey, $group, ['options' => $list, 'default' => $default] + $extra);
    }

    /** @param array<string,mixed> $extra @return Field */
    private function field(string $key, string $type, string $labelKey, string $group, array $extra = []): array
    {
        /** @var Field */
        return array_replace(['key' => $key, 'type' => $type, 'label_key' => $labelKey, 'group' => $group, 'prop' => 'props.' . $key], $extra);
    }
}
