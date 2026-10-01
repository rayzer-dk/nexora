<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Media\Application\MediaSlug;
use Commerce\Modules\Media\Application\ProductMediaOrder;
use PHPUnit\Framework\TestCase;

final class MediaSlugAndOrderTest extends TestCase
{
    public function testNamesBecomePlainAscii(): void
    {
        self::assertSame('iphone-15-pro', MediaSlug::make('iPhone 15 Pro'));
        self::assertSame('image', MediaSlug::make('???', 'image'));
        self::assertSame('a-b', MediaSlug::make('  a__b.. '));
        self::assertLessThanOrEqual(60, strlen(MediaSlug::make(str_repeat('abc ', 50))));
        self::assertMatchesRegularExpression('~^[a-z0-9\-]+$~', MediaSlug::make("Ґанок Київ <script>"));
        self::assertStringNotContainsString('.', MediaSlug::make('photo.final.v2'));
    }

    public function testPhotosAndVideosShareOneOrderAndTheMainPhotoComesFirstAmongPhotos(): void
    {
        $order = (new \ReflectionClass(ProductMediaOrder::class))->newInstanceWithoutConstructor();
        $items = $order->merge(
            [
                ['id' => 1, 'role' => 'gallery', 'sort_order' => 20],
                ['id' => 2, 'role' => 'primary', 'sort_order' => 50],
                ['id' => 3, 'role' => 'gallery', 'sort_order' => 30],
            ],
            [['id' => 9, 'sort_order' => 25], ['id' => 8, 'sort_order' => 5]],
        );
        // the main photo takes the place of the first photo (20); the video at 5 is before it, the video at 25 sits between photos
        self::assertSame(['v:8', 'p:2', 'p:1', 'v:9', 'p:3'], array_column($items, 'token'));
    }
}
