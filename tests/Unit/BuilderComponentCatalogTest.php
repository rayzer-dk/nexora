<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Appearance\Builder\BuilderComponentCatalog;
use Commerce\Modules\Appearance\Builder\LayoutSchemaValidator;
use PHPUnit\Framework\TestCase;

/** The properties panel of the builder is drawn from these descriptions, so every block a page may hold needs one. */
final class BuilderComponentCatalogTest extends TestCase
{
    private const TYPES = ['home', 'product', 'checkout', 'category', 'cart'];
    private const FIELD_TYPES = ['text', 'textarea', 'url', 'number', 'select', 'boolean', 'color', 'image', 'multilingual_text', 'multilingual_textarea'];

    public function testEveryAllowedComponentOfEveryTypeIsDescribed(): void
    {
        $catalog = new BuilderComponentCatalog();
        foreach (self::TYPES as $type) {
            $listed = array_column($catalog->components($type), 'component');
            self::assertSame($this->allowedForType($type), $this->sorted($listed), $type . ': the add-block list offers exactly the allowed components.');
            foreach ($listed as $component) {
                $fields = $catalog->fieldsFor($type, $component);
                self::assertNotNull($fields, $type . '/' . $component . ' has no description.');
                $own = array_values(array_diff(array_column($fields, 'prop'), array_column($catalog->commonFields(), 'prop')));
                self::assertTrue($own !== [] || $catalog->isGeneric($type, $component), $type . '/' . $component . ' has only the common fields but is not listed as generic.');
            }
        }
    }

    public function testEveryFieldIsWellFormed(): void
    {
        $catalog = new BuilderComponentCatalog();
        foreach (self::TYPES as $type) {
            foreach ($catalog->components($type) as $component) {
                $props = [];
                foreach ($component['fields'] as $field) {
                    self::assertContains($field['type'], self::FIELD_TYPES, $component['component'] . '.' . $field['key']);
                    self::assertMatchesRegularExpression('/^(id|(props|style|visibility)\.[a-z][a-z0-9_]*)$/', $field['prop']);
                    self::assertStringStartsWith('builder_', $field['label_key'], 'JS reads labels only from builder_ keys.');
                    self::assertStringStartsWith('builder_', $field['group']);
                    if ($field['type'] === 'select') {
                        self::assertNotEmpty($field['options'] ?? []);
                    }
                    $props[] = $field['prop'];
                }
                self::assertSame(count($props), count(array_unique($props)), $type . '/' . $component['component'] . ' repeats a property.');
            }
        }
    }

    public function testTheFieldsOfTheOldPanelAreStillThere(): void
    {
        $catalog = new BuilderComponentCatalog();
        $props = static fn (string $type, string $component): array => array_column($catalog->fieldsFor($type, $component) ?? [], 'prop');

        self::assertEmpty(array_diff(['props.image', 'props.image_mobile', 'props.width', 'props.height', 'props.position', 'props.overlay', 'props.slide', 'props.slide_interval', 'props.bleed', 'props.url', 'props.button_label', 'props.button_url', 'props.title', 'props.text'], $props('home', 'banner')));
        self::assertEmpty(array_diff(['props.layout', 'props.order', 'props.show_image', 'props.show_count', 'props.columns', 'props.limit'], $props('home', 'category_grid')), 'HomeController reads the tile count of the home category grid.');
        self::assertEmpty(array_diff(['props.layout', 'props.order', 'props.show_image', 'props.show_count', 'props.columns', 'props.limit'], $props('category', 'category_subcategories')));
        self::assertEmpty(array_diff(['props.display', 'props.source', 'props.limit'], $props('home', 'product_grid')));
        self::assertEmpty(array_diff(['props.image_side', 'props.step1_title', 'props.step4_text', 'props.step2_image'], $props('home', 'scroll_story')));
        self::assertEmpty(array_diff(['props.video_url', 'props.image', 'props.height', 'props.position', 'props.overlay', 'props.mobile_video'], $props('home', 'video_hero')));
        self::assertEmpty(array_diff(['props.data_source', 'props.title', 'props.text'], $props('product', 'rich_text')));
        self::assertEmpty(array_diff(['props.placement', 'props.show_region', 'props.show_city_search'], $props('checkout', 'checkout_shipping')));
        self::assertEmpty(array_diff(['props.always', 'props.show_country', 'props.show_postcode'], $props('checkout', 'checkout_address')));
        self::assertEmpty(array_diff(['props.show_name', 'props.require_name', 'props.show_phone', 'props.require_phone', 'props.show_email', 'props.require_email'], $props('checkout', 'checkout_contact')));
        foreach (['id', 'style.background', 'style.color', 'style.padding', 'style.margin', 'style.text_align', 'visibility.desktop', 'visibility.tablet', 'visibility.mobile'] as $common) {
            self::assertContains($common, $props('cart', 'cart_lines'));
        }
        self::assertNotContains('props.layout', $props('category', 'category_grid'), 'In a category the grid is the product list, not category tiles.');
    }

    public function testUnknownComponentHasNoDescription(): void
    {
        self::assertNull((new BuilderComponentCatalog())->fieldsFor('home', 'checkout_payment'));
        self::assertNull((new BuilderComponentCatalog())->fieldsFor('cart', 'no_such_block'));
    }

    /** @return list<string> */
    private function allowedForType(string $type): array
    {
        return $this->sorted(LayoutSchemaValidator::allowedForType($type));
    }

    /** @param list<string> $list @return list<string> */
    private function sorted(array $list): array
    {
        sort($list);

        return $list;
    }
}
