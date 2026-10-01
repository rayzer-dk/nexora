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
        mkdir($this->dir . '/public/media/catalog/2026/10/ab', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function service(int $generation = 1): MediaVariantService
    {
        $service = (new \ReflectionClass(MediaVariantService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'projectDir'))->setValue($service, $this->dir);
        (new \ReflectionProperty($service, 'profile'))->setValue($service, ['generation' => $generation] + MediaImageProfile::RECOMMENDED);

        return $service;
    }

    private function master(string $stem, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
        imagewebp($img, $this->dir . '/public/media/' . $stem . '.webp', 80);
    }

    public function testUrlsAndSrcsetNeedNoDatabase(): void
    {
        $s = $this->service(3);
        self::assertSame('/media/catalog/2026/10/ab/f00.card-g3.webp', $s->url('/media/catalog/2026/10/ab/f00.webp', 'card'));
        self::assertSame('/media/demo/x-1.thumb-g3.jpg', $s->url('/media/demo/x-1.jpg', 'thumb'));
        self::assertSame('/media/catalog/a.card-g3.webp 480w, /media/catalog/a.product-g3.webp 960w', $s->srcset('/media/catalog/a.webp', ['card', 'product']));
    }

    public function testNothingButStoredRasterPicturesIsRewritten(): void
    {
        $s = $this->service();
        foreach (['/assets/product-placeholder.svg', 'https://cdn.example.com/a.webp', '/media/demo/banner.svg', '/media/catalog/a.source.jpg', '/media/a/../b.webp', '', '/media/x y.webp'] as $url) {
            self::assertSame($url, $s->url($url, 'card'), $url);
        }
        self::assertSame('/media/a.webp', $s->url('/media/a.webp', 'huge'), 'an unknown size name is not a size');
        self::assertSame('', $s->srcset('/assets/p.svg', ['card']));
    }

    public function testOnlyClosedSetOfNamesParses(): void
    {
        $s = $this->service();
        self::assertSame(['stem' => 'catalog/a', 'preset' => 'zoom', 'gen' => 12, 'ext' => 'avif'], $s->parse('catalog/a.zoom-g12.avif'));
        foreach (['catalog/a.zoom-g12.svg', 'catalog/a.huge-g1.webp', '../a.card-g1.webp', 'catalog/../a.card-g1.webp', 'catalog/a.card-g.webp', 'a.card-g1.webp.php', 'catalog/a.b.card-g1.webp'] as $bad) {
            self::assertNull($s->parse($bad), $bad);
            self::assertNull($s->ensure($bad), $bad);
        }
    }

    public function testFirstRequestMakesTheFileAndLaterOnesFindIt(): void
    {
        $this->master('catalog/2026/10/ab/f00', 1920, 1080);
        $s = $this->service();
        $path = $s->ensure('catalog/2026/10/ab/f00.card-g1.webp');
        self::assertNotNull($path);
        self::assertSame(480, getimagesize($path)[0]);
        self::assertSame(270, getimagesize($path)[1]);
        self::assertSame($path, $s->ensure('catalog/2026/10/ab/f00.card-g1.webp'));
        self::assertSame([], glob($this->dir . '/public/media/catalog/2026/10/ab/*.tmp'), 'no half-written file is left behind');
    }

    public function testASmallMasterIsCopiedNotEnlarged(): void
    {
        $this->master('catalog/2026/10/ab/small', 300, 200);
        $path = $this->service()->ensure('catalog/2026/10/ab/small.product-g1.webp');
        self::assertSame(300, getimagesize((string) $path)[0]);
    }

    public function testMissingMasterAndWrongGenerationMakeNothing(): void
    {
        $this->master('catalog/2026/10/ab/f00', 1000, 1000);
        $s = $this->service(2);
        self::assertNull($s->ensure('catalog/2026/10/ab/none.card-g2.webp'));
        self::assertNull($s->ensure('catalog/2026/10/ab/f00.card-g1.webp'), 'an older generation is not made again');
        self::assertSame('/media/catalog/2026/10/ab/f00.card-g2.webp', $s->currentGenerationUrl('catalog/2026/10/ab/f00.card-g1.webp'));
        self::assertNull($s->currentGenerationUrl('catalog/2026/10/ab/f00.card-g2.webp'));
        self::assertNull($s->currentGenerationUrl('catalog/2026/10/ab/f00.card-g3.webp'), 'a newer generation is never redirected back to an older one');
        self::assertNull($s->ensure('catalog/2026/10/ab/f00.card-g3.webp'));
    }

    public function testCleanupRemovesOnlyOutdatedVariantsAndNeverMastersOrSources(): void
    {
        $dir = $this->dir . '/public/media/catalog/2026/10/ab';
        $this->master('catalog/2026/10/ab/keep', 800, 800);
        file_put_contents($dir . '/keep.source.jpg', 'ORIGINAL');
        $s = $this->service(2);
        $s->ensure('catalog/2026/10/ab/keep.card-g2.webp');
        copy($dir . '/keep.webp', $dir . '/keep.card-g1.webp');          // older generation
        copy($dir . '/keep.webp', $dir . '/gone.card-g2.webp');          // its master does not exist
        copy($dir . '/keep.webp', $dir . '/fresh.card-g1.webp');          // older generation but just made
        touch($dir . '/keep.card-g1.webp', time() - 10 * 86400);
        touch($dir . '/gone.card-g2.webp', time() - 10 * 86400);

        $dry = $s->collectStale(true);
        self::assertSame(2, $dry['files']);
        self::assertFileExists($dir . '/keep.card-g1.webp', 'a dry run changes nothing');

        $real = $s->collectStale(false);
        self::assertSame(2, $real['files']);
        self::assertFileDoesNotExist($dir . '/keep.card-g1.webp');
        self::assertFileDoesNotExist($dir . '/gone.card-g2.webp');
        foreach (['keep.webp', 'keep.source.jpg', 'keep.card-g2.webp', 'fresh.card-g1.webp'] as $kept) {
            self::assertFileExists($dir . '/' . $kept, $kept);
        }

        $all = $s->collectStale(false, 7, true);
        self::assertSame(2, $all['files'], 'clear-sizes removes every made size, still not the master or the source');
        self::assertFileExists($dir . '/keep.webp');
        self::assertFileExists($dir . '/keep.source.jpg');
    }
}
