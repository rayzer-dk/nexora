<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Media\Application\MediaImageProfile;
use Commerce\Modules\Media\Application\MediaVariantService;
use PHPUnit\Framework\TestCase;

final class MediaVariantServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nx-variants-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public/media/phones/apple', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string,mixed> $override */
    private function service(int $generation = 1, array $override = []): MediaVariantService
    {
        $service = (new \ReflectionClass(MediaVariantService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'projectDir'))->setValue($service, $this->dir);
        (new \ReflectionProperty($service, 'profile'))->setValue($service, ['generation' => $generation] + $override + MediaImageProfile::RECOMMENDED);

        return $service;
    }

    private function original(string $stem, int $width, int $height, string $ext = 'jpg'): void
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        $path = $this->dir . '/public/media/' . $stem . '.' . $ext;
        match ($ext) {
            'png' => imagepng($img, $path),
            'webp' => imagewebp($img, $path, 80),
            default => imagejpeg($img, $path, 85),
        };
    }

    public function testUrlsAndSrcsetNeedNoDatabaseAndLiveInTheCache(): void
    {
        $s = $this->service(3);
        self::assertSame('/media/cache/card-g3/phones/apple/iphone.webp', $s->url('/media/phones/apple/iphone.jpg', 'card'));
        self::assertSame('/media/cache/thumb-g3/demo/x-1.webp', $s->url('/media/demo/x-1.png', 'thumb'));
        self::assertSame('/media/cache/card-g3/a.webp 480w, /media/cache/product-g3/a.webp 960w', $s->srcset('/media/a.jpg', ['card', 'product']));
        self::assertSame('/media/cache/card-g3/a.avif 480w', $s->srcset('/media/a.jpg', ['card'], 'avif'));
        self::assertSame('/media/cache/card-g3/a.jpg', $this->service(3, ['format' => 'jpeg'])->url('/media/a.png', 'card'));
    }

    public function testNothingButStoredRasterOriginalsIsRewritten(): void
    {
        $s = $this->service();
        foreach (['/assets/product-placeholder.svg', 'https://cdn.example.com/a.webp', '/media/demo/banner.svg', '/media/cache/card-g1/a.webp', '/media/a/../b.webp', '', '/media/x y.webp', '/media/video/ab/c.mp4'] as $url) {
            self::assertSame($url, $s->url($url, 'card'), $url);
        }
        self::assertSame('/media/a.webp', $s->url('/media/a.webp', 'huge'), 'an unknown size name is not a size');
        self::assertSame('', $s->srcset('/assets/p.svg', ['card']));
    }

    public function testOnlyClosedSetOfNamesParses(): void
    {
        $s = $this->service();
        self::assertSame(['stem' => 'phones/a', 'preset' => 'zoom', 'gen' => 12, 'ext' => 'avif'], $s->parse('cache/zoom-g12/phones/a.avif'));
        foreach (['cache/zoom-g12/a.svg', 'cache/huge-g1/a.webp', 'cache/card-g1/../a.webp', 'cache/card-g/a.webp', 'cache/card-g1/a.webp.php', 'cache/card-g1/a.b.webp', 'card-g1/a.webp', 'cache/card-g1/a.png'] as $bad) {
            self::assertNull($s->parse($bad), $bad);
            self::assertNull($s->ensure($bad), $bad);
        }
    }

    public function testFirstRequestMakesTheFileFromTheOriginalAndLaterOnesFindIt(): void
    {
        $this->original('phones/apple/f00', 2400, 1350);
        $s = $this->service();
        $path = $s->ensure('cache/card-g1/phones/apple/f00.webp');
        self::assertNotNull($path);
        self::assertSame(480, getimagesize($path)[0]);
        self::assertSame(270, getimagesize($path)[1]);
        self::assertSame($path, $s->ensure('cache/card-g1/phones/apple/f00.webp'));
        self::assertSame([], glob($this->dir . '/public/media/cache/card-g1/phones/apple/*.tmp'), 'no half-written file is left behind');
        self::assertFileExists($this->dir . '/public/media/phones/apple/f00.jpg', 'the original is never touched');
    }

    public function testAnOriginalOfAnyFormatServesEveryOutputFormat(): void
    {
        $this->original('phones/apple/p', 1000, 500, 'png');
        $s = $this->service();
        self::assertSame('image/webp', getimagesize((string) $s->ensure('cache/product-g1/phones/apple/p.webp'))['mime']);
        self::assertSame('image/jpeg', getimagesize((string) $s->ensure('cache/thumb-g1/phones/apple/p.jpg'))['mime']);
        if (function_exists('imageavif')) {
            self::assertSame('image/avif', getimagesize((string) $s->ensure('cache/card-g1/phones/apple/p.avif'))['mime']);
        }
    }

    public function testASmallOriginalIsNotEnlarged(): void
    {
        $this->original('phones/apple/small', 300, 200);
        $path = $this->service()->ensure('cache/product-g1/phones/apple/small.webp');
        self::assertSame(300, getimagesize((string) $path)[0]);
    }

    public function testAvifIsOfferedOnlyWhenChosenAndSupported(): void
    {
        self::assertFalse($this->service()->avifEnabled());
        self::assertSame(function_exists('imageavif'), $this->service(1, ['format' => 'avif_webp'])->avifEnabled());
        self::assertFalse($this->service(1, ['format' => 'jpeg'])->avifEnabled());
    }

    public function testMissingOriginalAndWrongGenerationMakeNothing(): void
    {
        $this->original('phones/apple/f00', 1000, 1000);
        $s = $this->service(2);
        self::assertNull($s->ensure('cache/card-g2/phones/apple/none.webp'));
        self::assertNull($s->ensure('cache/card-g1/phones/apple/f00.webp'), 'an older generation is not made again');
        self::assertSame('/media/cache/card-g2/phones/apple/f00.webp', $s->currentGenerationUrl('cache/card-g1/phones/apple/f00.webp'));
        self::assertNull($s->currentGenerationUrl('cache/card-g2/phones/apple/f00.webp'));
        self::assertNull($s->currentGenerationUrl('cache/card-g3/phones/apple/f00.webp'), 'a newer generation is never redirected back to an older one');
        self::assertNull($s->ensure('cache/card-g3/phones/apple/f00.webp'));
    }

    public function testCleanupRemovesOnlyOutdatedCacheAndNeverOriginals(): void
    {
        $media = $this->dir . '/public/media';
        $this->original('phones/apple/keep', 800, 800);
        $s = $this->service(2);
        $s->ensure('cache/card-g2/phones/apple/keep.webp');
        foreach (['cache/card-g1/phones/apple/keep.webp', 'cache/card-g2/phones/apple/gone.webp', 'cache/card-g1/phones/apple/fresh.webp'] as $name) {
            @mkdir(dirname($media . '/' . $name), 0777, true);
            copy($media . '/cache/card-g2/phones/apple/keep.webp', $media . '/' . $name);
        }
        touch($media . '/cache/card-g1/phones/apple/keep.webp', time() - 10 * 86400); // older generation
        touch($media . '/cache/card-g2/phones/apple/gone.webp', time() - 10 * 86400); // its original does not exist

        $dry = $s->collectStale(true);
        self::assertSame(2, $dry['files']);
        self::assertFileExists($media . '/cache/card-g1/phones/apple/keep.webp', 'a dry run changes nothing');

        $real = $s->collectStale(false);
        self::assertSame(2, $real['files']);
        self::assertFileDoesNotExist($media . '/cache/card-g1/phones/apple/keep.webp');
        self::assertFileDoesNotExist($media . '/cache/card-g2/phones/apple/gone.webp');
        foreach (['phones/apple/keep.jpg', 'cache/card-g2/phones/apple/keep.webp', 'cache/card-g1/phones/apple/fresh.webp'] as $kept) {
            self::assertFileExists($media . '/' . $kept, $kept);
        }

        $all = $s->collectStale(false, 7, true);
        self::assertSame(2, $all['files'], 'clearing everything removes every made size, never an original');
        self::assertFileExists($media . '/phones/apple/keep.jpg');
        self::assertDirectoryDoesNotExist($media . '/cache/card-g2', 'empty cache folders are removed too');
    }

    public function testForgetRemovesTheCacheOfOnePictureOnly(): void
    {
        $this->original('phones/apple/a', 800, 800);
        $this->original('phones/apple/b', 800, 800);
        $s = $this->service();
        $s->ensure('cache/card-g1/phones/apple/a.webp');
        $s->ensure('cache/thumb-g1/phones/apple/a.webp');
        $s->ensure('cache/card-g1/phones/apple/b.webp');
        self::assertSame(2, $s->forget('phones/apple/a.jpg'));
        self::assertFileExists($this->dir . '/public/media/cache/card-g1/phones/apple/b.webp');
        self::assertFileExists($this->dir . '/public/media/phones/apple/a.jpg');
    }
}
