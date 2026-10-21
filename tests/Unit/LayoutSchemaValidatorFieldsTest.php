<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Appearance\Builder\LayoutSchemaValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Values of described builder fields are checked on save; layouts saved by 4.0.0 keep every value. */
final class LayoutSchemaValidatorFieldsTest extends TestCase
{
    /** @return iterable<string,array{string,list<array<string,mixed>>}> layouts as the 4.0.0 panel saved them (numbers as text) */
    public static function savedLayouts(): iterable
    {
        $style = ['background' => 'linear-gradient(90deg, #111827, #1f2937)', 'color' => 'rgba(255,255,255,0.9)', 'padding' => '24px 16px', 'margin' => '0', 'text_align' => 'center'];
        $visibility = ['desktop' => true, 'tablet' => false, 'mobile' => true];
        yield 'home' => ['home', [
            self::block('banner', ['title' => 'Розпродаж', 'text' => 'До -50%', 'url' => '/catalog', 'button_label' => 'Купити', 'button_url' => 'https://shop.example/sale', 'image' => '/media/a.jpg', 'image_mobile' => '/media/a-m.jpg', 'width' => 'half', 'height' => 'l', 'position' => 'bc', 'overlay' => '30', 'slide' => true, 'slide_interval' => '7', 'bleed' => false], $style, $visibility),
            self::block('scroll_story', ['title' => 'Як ми працюємо', 'image_side' => 'right', 'step1_title' => 'Крок', 'step1_text' => 'Текст', 'step1_image' => '/media/s1.jpg']),
            self::block('video_hero', ['video_url' => '/media/v.mp4', 'image' => '/media/p.jpg', 'height' => 'screen', 'position' => 'mc', 'overlay' => 35, 'mobile_video' => true]),
            self::block('hero', ['title' => 'Магазин', 'text' => 'Опис', 'image' => '/media/h.jpg', 'button_label' => 'Каталог', 'button_url' => 'catalog/phones', 'columns' => '3', 'limit' => '8']),
            self::block('category_grid', ['title' => 'Категорії', 'columns' => '4', 'layout' => 'scroll', 'order' => 'popular', 'show_image' => false, 'show_count' => true]),
            self::block('product_grid', ['title' => 'Хіти', 'display' => 'carousel', 'source' => 'popular', 'limit' => '12']),
            self::block('recently_viewed', []),
            self::block('article_grid', ['title' => 'Блог']),
        ]];
        yield 'product' => ['product', [
            self::block('product_gallery', [], ['background' => '#ffffff']),
            self::block('rich_text', ['title' => 'Доставка', 'text' => 'Щодня', 'data_source' => 'product.brand', 'button_label' => 'Детальніше', 'button_url' => '/delivery']),
            self::block('image', ['image' => '/media/i.jpg', 'url' => 'mailto:shop@example.com']),
            self::block('info_card', ['title' => 'Гарантія', 'text' => '12 місяців']),
        ]];
        yield 'checkout' => ['checkout', [
            self::block('checkout_contact', ['placement' => 'left', 'show_name' => true, 'require_name' => false, 'show_phone' => true, 'require_phone' => true, 'show_email' => true, 'require_email' => true]),
            self::block('checkout_shipping', ['placement' => 'full', 'show_region' => false, 'show_city_search' => true, 'title' => 'Доставка']),
            self::block('checkout_address', ['always' => true, 'show_country' => false, 'show_postcode' => true]),
            self::block('checkout_summary', ['placement' => 'right', 'button_label' => 'Підтвердити']),
        ]];
        yield 'category' => ['category', [
            self::block('category_heading', []),
            self::block('category_subcategories', ['layout' => 'grid', 'columns' => '5', 'limit' => '30', 'order' => 'name', 'show_image' => true, 'show_count' => false]),
            self::block('category_grid', ['title' => 'set by 4.0.0 although unused', 'layout' => 'grid']),
        ]];
        yield 'cart' => ['cart', [
            self::block('cart_heading', ['title' => 'Кошик']),
            self::block('cart_lines', []),
            self::block('cart_saved', [], ['padding' => '2rem'], ['mobile' => false]),
        ]];
    }

    /** @param list<array<string,mixed>> $blocks */
    #[\PHPUnit\Framework\Attributes\DataProvider('savedLayouts')]
    public function testLayoutOfVersion400OpensAndSavesWithoutLosses(string $type, array $blocks): void
    {
        $validator = new LayoutSchemaValidator();
        $layout = ['schema_version' => 1, 'blocks' => $blocks];

        foreach ([$validator->validate($layout, $type), $validator->validate($layout, $type, true)] as $result) {
            foreach ($blocks as $index => $block) {
                self::assertSame($block['props'], $result['blocks'][$index]['props'], $type . '/' . $block['component']);
                self::assertSame($block['style'], $result['blocks'][$index]['style']);
                self::assertSame($block['visibility'], $result['blocks'][$index]['visibility']);
            }
        }
    }

    /** @return iterable<string,array{array<string,mixed>,array<string,mixed>}> */
    public static function invalidValues(): iterable
    {
        yield 'script link' => [['button_url' => 'javascript:alert(1)'], []];
        yield 'link with a space' => [['url' => '/sale page'], []];
        yield 'number as words' => [['overlay' => 'half'], []];
        yield 'number above the maximum' => [['overlay' => 95], []];
        yield 'option that does not exist' => [['width' => 'huge'], []];
        yield 'yes/no as text' => [['slide' => 'maybe'], []];
        yield 'colour name' => [[], ['background' => 'red']];
        yield 'colour with a script' => [[], ['color' => 'expression(alert(1))']];
    }

    public function testAPhoneLinkMayHaveSpacesButNoOtherWhitespace(): void
    {
        $validator = new LayoutSchemaValidator();
        $layout = static fn (string $url): array => ['schema_version' => 1, 'blocks' => [self::block('banner', ['button_url' => $url])]];

        $saved = $validator->validate($layout('tel:+380 44 123 45 67'), 'home', true);
        self::assertSame('tel:+380 44 123 45 67', $saved['blocks'][0]['props']['button_url']);

        foreach (["tel:+380\u{00A0}44", "tel:+380\t44", 'mailto:a b@example.com'] as $url) {
            try {
                $validator->validate($layout($url), 'home', true);
                self::fail('A link with other whitespace was accepted: ' . json_encode($url));
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<string,mixed> $props @param array<string,mixed> $style */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValues')]
    public function testSavingRejectsAnInvalidValue(array $props, array $style): void
    {
        $this->expectException(RuntimeException::class);
        (new LayoutSchemaValidator())->validate(['schema_version' => 1, 'blocks' => [self::block('banner', $props, $style)]], 'home', true);
    }

    public function testReadingDropsInvalidValuesAndKeepsNumbersInRange(): void
    {
        $block = self::block('banner', ['button_url' => 'javascript:alert(1)', 'overlay' => '95', 'slide_interval' => -3, 'width' => 'huge', 'title' => 'Stays', 'unknown_key' => 'stays too'], ['background' => 'red', 'color' => '#fff']);
        $result = (new LayoutSchemaValidator())->validate(['schema_version' => 1, 'blocks' => [$block]], 'home');

        self::assertSame(['overlay' => 80, 'slide_interval' => 0, 'title' => 'Stays', 'unknown_key' => 'stays too'], $result['blocks'][0]['props']);
        self::assertSame(['color' => '#fff'], $result['blocks'][0]['style']);
    }

    public function testErrorNamesTheBlockAndTheField(): void
    {
        try {
            (new LayoutSchemaValidator())->validate(['schema_version' => 1, 'blocks' => [self::block('video_hero', ['video_url' => 'data:video/mp4;base64,AAAA'])]], 'home', true);
            self::fail('The video link was accepted.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('video_hero_1', $e->getMessage());
            self::assertStringContainsString('video_url', $e->getMessage());
        }
    }

    /** @param array<string,mixed> $props @param array<string,mixed> $style @param array<string,mixed> $visibility @return array<string,mixed> */
    private static function block(string $component, array $props, array $style = [], array $visibility = []): array
    {
        return ['id' => $component . '_1', 'component' => $component, 'enabled' => true, 'props' => $props, 'style' => $style, 'visibility' => $visibility];
    }
}
