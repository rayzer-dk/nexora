<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Media\Application\MediaImageProfile;
use PHPUnit\Framework\TestCase;

final class MediaImageProfileTest extends TestCase
{
    public function testEmptyPayloadMeansRecommendedWebp(): void
    {
        $p = MediaImageProfile::normalize([]);
        self::assertSame('webp', $p['format']);
        self::assertSame(['thumb' => 160, 'card' => 480, 'product' => 960, 'zoom' => 1600], $p['presets']);
        self::assertSame(60, $p['avif_quality']);
        self::assertSame(1, $p['generation']);
        self::assertSame('recommended', MediaImageProfile::detect([]));
    }

    public function testFormatsAreWebpAvifWithWebpFallbackOrJpeg(): void
    {
        self::assertSame(['webp', 'avif_webp', 'jpeg'], MediaImageProfile::FORMATS);
        self::assertSame('avif_webp', MediaImageProfile::normalize(['format' => 'avif'])['format'], 'an older "avif" profile means AVIF with the WebP fallback');
        self::assertSame('webp', MediaImageProfile::normalize(['format' => 'png'])['format']);
        self::assertSame('webp', MediaImageProfile::extension('avif_webp'), 'the fallback file of AVIF mode is WebP');
        self::assertSame('jpg', MediaImageProfile::extension('jpeg'));
    }

    public function testPresetsAndDetection(): void
    {
        self::assertSame('jpeg', MediaImageProfile::fromPreset('compatible')['format']);
        self::assertSame('avif_webp', MediaImageProfile::fromPreset('modern')['format']);
        foreach (['recommended', 'compatible', 'modern'] as $preset) {
            self::assertSame($preset, MediaImageProfile::detect(MediaImageProfile::fromPreset($preset)));
        }
        self::assertSame('custom', MediaImageProfile::detect(['format' => 'jpeg', 'quality' => 70]));
        self::assertSame('recommended', MediaImageProfile::detect(['generation' => 7] + MediaImageProfile::RECOMMENDED), 'the generation never changes which preset a profile is');
    }

    public function testCustomInputIsClamped(): void
    {
        $p = MediaImageProfile::fromPreset('custom', ['format' => 'bmp', 'presets' => ['thumb' => 1, 'card' => 640, 'product' => 5000, 'zoom' => 1920], 'quality' => 500, 'avif_quality' => 1]);
        self::assertSame('webp', $p['format']);
        self::assertSame(['thumb' => 160, 'card' => 640, 'product' => 960, 'zoom' => 1920], $p['presets'], 'a width outside the allowed set falls back to the default');
        self::assertSame(95, $p['quality']);
        self::assertSame(30, $p['avif_quality']);
    }

    public function testGenerationRisesOnlyWhenSizesOrQualityChange(): void
    {
        $base = MediaImageProfile::RECOMMENDED;
        self::assertSame(1, MediaImageProfile::withGeneration($base, $base)['generation']);
        self::assertSame(1, MediaImageProfile::withGeneration($base, ['format' => 'avif_webp'] + $base)['generation'], 'the format has its own file extension, no new generation is needed');
        $wider = ['presets' => ['card' => 640] + $base['presets']] + $base;
        $next = MediaImageProfile::withGeneration($base, $wider);
        self::assertSame(2, $next['generation']);
        self::assertSame(3, MediaImageProfile::withGeneration($next, ['quality' => 70] + $next)['generation']);
        self::assertSame(4, MediaImageProfile::withGeneration(['generation' => 3] + $next, ['avif_quality' => 50] + $next)['generation']);
    }
}
