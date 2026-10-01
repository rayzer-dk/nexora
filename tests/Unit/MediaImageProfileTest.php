<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Media\Application\MediaImageProfile;
use Commerce\Modules\Media\Application\MediaImageService;
use PHPUnit\Framework\TestCase;

final class MediaImageProfileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nx-media-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public/media', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function testEmptyPayloadMeansRecommendedWebp(): void
    {
        $p = MediaImageProfile::normalize([]);
        self::assertSame('webp', $p['format']);
        self::assertSame(['thumb' => 160, 'card' => 480, 'product' => 960, 'zoom' => 1600], $p['presets']);
        self::assertTrue($p['keep_source']);
        self::assertSame(1, $p['generation']);
        self::assertSame('recommended', MediaImageProfile::detect([]));
    }

    public function testPresetsAndDetection(): void
    {
        self::assertSame('jpeg', MediaImageProfile::fromPreset('compatible')['format']);
        self::assertSame('avif', MediaImageProfile::fromPreset('modern')['format']);
        foreach (['recommended', 'compatible', 'modern'] as $preset) {
            self::assertSame($preset, MediaImageProfile::detect(MediaImageProfile::fromPreset($preset)));
        }
        self::assertSame('custom', MediaImageProfile::detect(['format' => 'jpeg', 'quality' => 70]));
        self::assertSame('recommended', MediaImageProfile::detect(['generation' => 7] + MediaImageProfile::RECOMMENDED), 'the generation never changes which preset a profile is');
    }

    public function testCustomInputIsClamped(): void
    {
        $p = MediaImageProfile::fromPreset('custom', ['format' => 'bmp', 'presets' => ['thumb' => 1, 'card' => 640, 'product' => 5000, 'zoom' => 1920], 'quality' => 500]);
        self::assertSame('webp', $p['format']);
        self::assertSame(['thumb' => 160, 'card' => 640, 'product' => 960, 'zoom' => 1920], $p['presets'], 'a width outside the allowed set falls back to the default');
        self::assertSame(95, $p['quality']);
    }

    public function testGenerationRisesOnlyWhenSizesOrQualityChange(): void
    {
        $base = MediaImageProfile::RECOMMENDED;
        self::assertSame(1, MediaImageProfile::withGeneration($base, $base)['generation']);
        self::assertSame(1, MediaImageProfile::withGeneration($base, ['keep_source' => false] + $base)['generation'], 'keeping the original does not change any file name');
        $wider = ['presets' => ['card' => 640] + $base['presets']] + $base;
        $next = MediaImageProfile::withGeneration($base, $wider);
        self::assertSame(2, $next['generation']);
        self::assertSame(3, MediaImageProfile::withGeneration($next, ['quality' => 70] + $next)['generation']);
    }

    public function testUploadWritesOneMasterCappedAt1920AndTheSourceIsMarked(): void
    {
        $service = (new \ReflectionClass(MediaImageService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'projectDir'))->setValue($service, $this->dir);
        $img = imagecreatetruecolor(2400, 1200);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 10, 120, 200, 64));
        $generate = new \ReflectionMethod($service, 'generateDerivatives');
        $list = $generate->invoke($service, $img, 2400, 1200, 'catalog/t/abc', 'image/png', MediaImageProfile::RECOMMENDED);
        self::assertCount(1, $list, 'one master file, not one file per size');
        self::assertSame('webp', $list[0]['format']);
        self::assertSame(1920, $list[0]['width']);
        self::assertSame('catalog/t/abc.webp', $list[0]['key']);
        self::assertFileExists($this->dir . '/public/media/catalog/t/abc.webp');

        $keep = new \ReflectionMethod($service, 'keepSource');
        $source = $keep->invoke($service, 'RAWBYTES', 'catalog/t/abc', 'image/png', 2400, 1200);
        self::assertSame('source', $source['role']);
        self::assertSame('catalog/t/abc.source.png', $source['key']);
        self::assertSame('RAWBYTES', file_get_contents($this->dir . '/public/media/' . $source['key']));

        $preferred = new \ReflectionMethod($service, 'preferredDerivative');
        self::assertSame('catalog/t/abc.webp', $preferred->invoke($service, [...$list, $source])['key'], 'the asset points at the master, never at the source');
    }
}
