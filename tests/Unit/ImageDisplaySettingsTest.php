<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Appearance\Infrastructure\ImageDisplaySettings;
use PHPUnit\Framework\TestCase;

final class ImageDisplaySettingsTest extends TestCase
{
    public function testTheDefaultIsContainUpToThreeTimesWithoutOwnValuesPerPlace(): void
    {
        $defaults = ImageDisplaySettings::defaults();

        self::assertSame('none', $defaults['image_background']);
        self::assertSame('contain', $defaults['image_fit']);
        self::assertSame('3', $defaults['image_upscale']);
        self::assertSame('--mc-fit-cards:contain;--mc-up-cards:3;--mc-fit-gallery:contain;--mc-up-gallery:3;--mc-fit-thumbs:contain;--mc-up-thumbs:3', ImageDisplaySettings::style($defaults));
    }

    public function testAnythingOutsideTheAllowedValuesFallsBackToTheDefault(): void
    {
        $clean = ImageDisplaySettings::normalize([
            'image_background' => 'red',
            'image_fit' => 'stretch',
            'image_upscale' => '99',
            'image_fit_cards' => 'fill',
            'image_upscale_gallery' => 'x',
        ]);

        self::assertSame(ImageDisplaySettings::defaults(), $clean);
    }

    public function testEachPlaceTakesItsOwnValueOrTheCommonOne(): void
    {
        $style = ImageDisplaySettings::style([
            'image_fit' => 'contain',
            'image_upscale' => '2',
            'image_fit_cards' => 'cover',
            'image_upscale_cards' => 'unlimited',
            'image_upscale_thumbs' => 'none',
        ]);

        self::assertSame('--mc-fit-cards:cover;--mc-up-cards:1000;--mc-pad-cards:0;--mc-fit-gallery:contain;--mc-up-gallery:2;--mc-fit-thumbs:contain;--mc-up-thumbs:1', $style);
    }

    public function testTheDarkBackgroundIsOffered(): void
    {
        self::assertSame('dark', ImageDisplaySettings::normalize(['image_background' => 'dark'])['image_background']);
    }
}
