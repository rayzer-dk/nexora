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
        self::assertSame([640, 960, 1280], $p['widths']);
        self::assertTrue($p['keep_source']);
        self::assertFalse($p['jpeg_fallback']);
        self::assertSame('recommended', MediaImageProfile::detect([]));
    }

    public function testPresetsAndDetection(): void
    {
        self::assertTrue(MediaImageProfile::fromPreset('compatible')['jpeg_fallback']);
        self::assertSame('avif', MediaImageProfile::fromPreset('modern')['format']);
        foreach (['recommended', 'compatible', 'modern'] as $preset) {
            self::assertSame($preset, MediaImageProfile::detect(MediaImageProfile::fromPreset($preset)));
        }
        self::assertSame('custom', MediaImageProfile::detect(['format' => 'jpeg', 'widths' => [640], 'quality' => 70]));
    }

    public function testCustomInputIsClamped(): void
    {
        $p = MediaImageProfile::fromPreset('custom', ['format' => 'bmp', 'widths' => [1, 640, 640, 5000, 1920], 'quality' => 500, 'include_original' => false]);
        self::assertSame('webp', $p['format']);
        self::assertSame([640, 1920], $p['widths']);
        self::assertSame(95, $p['quality']);
        self::assertFalse($p['include_original']);
        self::assertFalse(MediaImageProfile::normalize(['format' => 'webp'])['keep_source'], 'a payload saved before 3.23 keeps not storing originals');
    }

    public function testDerivativesAreWebpCappedAndSourceAndFallbackAreMarked(): void
    {
        $service = (new \ReflectionClass(MediaImageService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'projectDir'))->setValue($service, $this->dir);
        $img = imagecreatetruecolor(2400, 1200);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 10, 120, 200, 64));
        $profile = MediaImageProfile::fromPreset('compatible');
        $generate = new \ReflectionMethod($service, 'generateDerivatives');
        $list = $generate->invoke($service, $img, 2400, 1200, 'catalog/t/abc', 'image/png', $profile);
        $byWidth = [];
        foreach ($list as $d) {
            if (!isset($d['role'])) {
                self::assertSame('webp', $d['format']);
                self::assertFileExists($this->dir . '/public/media/' . $d['key']);
                $byWidth[] = $d['width'];
            }
        }
        self::assertSame([640, 960, 1280, 1920], $byWidth, 'full size copy is capped at 1920');
        $fallback = array_values(array_filter($list, static fn (array $d): bool => ($d['role'] ?? '') === 'fallback'))[0];
        self::assertSame('jpeg', $fallback['format']);
        self::assertSame(1280, $fallback['width']);
        $px = imagecreatefromjpeg($this->dir . '/public/media/' . $fallback['key']);
        $rgb = imagecolorat($px, 5, 5);
        self::assertGreaterThan(100, ($rgb >> 16) & 255, 'transparent areas are flattened onto white, not black');

        $keep = new \ReflectionMethod($service, 'keepSource');
        $source = $keep->invoke($service, 'RAWBYTES', 'catalog/t/abc', 'image/png', 2400, 1200);
        self::assertSame('source', $source['role']);
        self::assertStringEndsWith('-source.png', $source['key']);
        self::assertSame('RAWBYTES', file_get_contents($this->dir . '/public/media/' . $source['key']));

        $preferred = new \ReflectionMethod($service, 'preferredDerivative');
        $picked = $preferred->invoke($service, [...$list, $source]);
        self::assertSame(1920, $picked['width']);
        self::assertArrayNotHasKey('role', $picked);
    }
}
