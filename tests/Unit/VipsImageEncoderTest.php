<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Media\Application\MediaImageProfile;
use Commerce\Modules\Media\Application\MediaVariantService;
use Commerce\Modules\Media\Application\VipsImageEncoder;
use Commerce\Tests\Unit\Support\MediaVariantServiceFactory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;

/**
 * libvips is driven through its command line tool. These tests put a stand-in "vips" in place (a small PHP script that
 * answers --version and -l foreign like libvips and makes the thumbnail with GD), so they run where libvips is absent.
 */
final class VipsImageEncoderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nx-vips-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public/media/phones', 0777, true);
        mkdir($this->dir . '/var', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** A stand-in for the vips tool; $savers is what "-l foreign" lists, $fail makes "thumbnail" exit with an error. */
    private function fakeVips(string $savers = 'heifsave webpsave jpegsave', bool $fail = false): string
    {
        $script = $this->dir . '/fake-vips.php';
        $log = $this->dir . '/vips-calls.log';
        file_put_contents($script, '<?php
$a = array_slice($argv, 1);
file_put_contents(' . var_export($log, true) . ', implode(" ", $a) . "\n", FILE_APPEND);
if ($a === ["--version"]) { echo "vips-8.16.0\n"; exit(0); }
if (($a[0] ?? "") === "-l") { echo ' . var_export($savers, true) . ' . "\n"; exit(0); }
if (($a[0] ?? "") === "thumbnail") {
    if (' . var_export($fail, true) . ') { fwrite(STDERR, "VipsForeignSave: failed\n"); exit(1); }
    $out = preg_replace("~\\\\[[^\\\\]]*\\\\]$~", "", $a[2]);
    $src = imagecreatefromstring(file_get_contents($a[1]));
    $w = min((int) $a[3], imagesx($src));
    $dst = imagescale($src, $w);
    $ext = pathinfo($out, PATHINFO_EXTENSION);
    $ok = $ext === "avif" ? imageavif($dst, $out, 50) : ($ext === "webp" ? imagewebp($dst, $out, 80) : imagejpeg($dst, $out, 80));
    exit($ok ? 0 : 1);
}
exit(2);
');
        if (\DIRECTORY_SEPARATOR === '\\') {
            $tool = $this->dir . '/vips.bat';
            file_put_contents($tool, '@"' . PHP_BINARY . '" "' . $script . '" %*' . "\r\n");
        } else {
            $tool = $this->dir . '/vips';
            file_put_contents($tool, "#!/bin/sh\nexec '" . PHP_BINARY . "' '" . $script . "' \"\$@\"\n");
            chmod($tool, 0755);
        }

        return $tool;
    }

    /** @return list<string> */
    private function calls(): array
    {
        $log = $this->dir . '/vips-calls.log';

        return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    }

    private function original(string $stem, int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 30, 120, 200));
        $path = $this->dir . '/public/media/' . $stem . '.jpg';
        imagejpeg($img, $path, 85);

        return $path;
    }

    private function service(VipsImageEncoder $vips, array $override = []): MediaVariantService
    {
        return MediaVariantServiceFactory::make($this->dir, $override + MediaImageProfile::RECOMMENDED, $vips);
    }

    public function testTheToolIsFoundOnceAndItsSaversDecideTheFormats(): void
    {
        $encoder = new VipsImageEncoder($this->dir, 'auto', $this->fakeVips('webpsave jpegsave'));
        self::assertTrue($encoder->available());
        self::assertSame('vips-8.16.0', $encoder->version());
        self::assertTrue($encoder->supports('webp'));
        self::assertFalse($encoder->supports('avif'), 'no heifsave, no AVIF');
        self::assertFileExists($this->dir . '/var/media-engine.json');

        $again = new VipsImageEncoder($this->dir, 'auto', $this->dir . (\DIRECTORY_SEPARATOR === '\\' ? '/vips.bat' : '/vips'));
        self::assertTrue($again->available());
        self::assertCount(2, $this->calls(), 'the second instance reads the cached probe instead of starting the tool');
    }

    public function testGdOnlySettingAndAMissingToolLeaveLibvipsOff(): void
    {
        self::assertFalse((new VipsImageEncoder($this->dir, 'gd', $this->fakeVips()))->available());
        self::assertSame([], $this->calls(), 'MEDIA_IMAGE_ENGINE=gd never starts the tool');
        $missing = new VipsImageEncoder($this->dir . '/other', 'auto', $this->dir . '/no-such-vips');
        self::assertFalse($missing->available());
        self::assertFalse($missing->thumbnail($this->original('phones/a', 800, 400), $this->dir . '/out.webp', 160, 'webp', 80));
    }

    public function testThumbnailScalesDownStripsMetadataAndPassesTheQuality(): void
    {
        $encoder = new VipsImageEncoder($this->dir, 'auto', $this->fakeVips());
        $out = $this->dir . '/out.webp';
        self::assertTrue($encoder->thumbnail($this->original('phones/b', 1200, 600), $out, 480, 'webp', 77));
        self::assertSame([480, 240], array_slice((array) getimagesize($out), 0, 2));
        $thumbnail = array_values(array_filter($this->calls(), static fn (string $c): bool => str_starts_with($c, 'thumbnail')));
        self::assertStringContainsString('out.webp[Q=77,effort=4,strip] 480 --size down', $thumbnail[0]);
    }

    public function testVariantsAreMadeByLibvipsAndAFailingToolFallsBackToGd(): void
    {
        $this->original('phones/c', 2000, 1000);
        $service = $this->service(new VipsImageEncoder($this->dir, 'auto', $this->fakeVips()));
        $path = $service->ensure('cache/card-g1/phones/c.webp');
        self::assertNotNull($path);
        self::assertSame(480, getimagesize($path)[0]);
        self::assertNotEmpty(array_filter($this->calls(), static fn (string $c): bool => str_starts_with($c, 'thumbnail')));
        self::assertSame([], glob($this->dir . '/public/media/cache/card-g1/phones/.vips-*'), 'no temporary file is left');
        self::assertStringStartsWith('vips-8.16.0', $service->engine());

        @unlink($this->dir . '/var/media-engine.json');
        $broken = $this->service(new VipsImageEncoder($this->dir, 'auto', $this->fakeVips('heifsave webpsave jpegsave', true)));
        $fallback = $broken->ensure('cache/product-g1/phones/c.webp');
        self::assertNotNull($fallback, 'GD makes the size when libvips fails');
        self::assertSame(960, getimagesize($fallback)[0]);
    }

    public function testLibvipsMakesAvifAvailableAndPagesQueueItForTheBackgroundJob(): void
    {
        $this->original('phones/d', 1600, 800);
        $service = $this->service(new VipsImageEncoder($this->dir, 'auto', $this->fakeVips()), ['format' => 'avif_webp']);
        self::assertTrue($service->canWriteAvif());
        self::assertTrue($service->avifEnabled());

        $service->queue('cache/card-g1/phones/d.avif');
        $service->queue('cache/card-g1/phones/d.avif');
        $service->queue('cache/card-g1/../d.avif');
        self::assertSame(1, $service->queueLength(), 'one job per size; anything that is not a size is ignored');
        self::assertFileDoesNotExist($this->dir . '/public/media/cache/card-g1/phones/d.avif', 'queueing makes nothing yet');

        $service->queue('cache/thumb-g1/phones/gone.avif');
        self::assertSame(1, $service->processQueue());
        self::assertSame(0, $service->queueLength(), 'made and not makeable jobs are both removed');
    }

    #[RequiresFunction('imageavif')]
    public function testAQueuedAvifIsWrittenByTheBackgroundJob(): void
    {
        $this->original('phones/d', 1600, 800);
        $service = $this->service(new VipsImageEncoder($this->dir, 'auto', $this->fakeVips()), ['format' => 'avif_webp']);
        $service->queue('cache/card-g1/phones/d.avif');

        self::assertSame(1, $service->processQueue());
        self::assertSame('image/avif', getimagesize($this->dir . '/public/media/cache/card-g1/phones/d.avif')['mime']);
    }

    public function testAPageAskingForAMissingAvifGetsTheWebpAtOnceAndTheAvifIsQueued(): void
    {
        $this->original('phones/e', 1600, 800);
        $service = $this->service(new VipsImageEncoder($this->dir, 'gd'), ['format' => 'avif_webp']);
        $controller = new \Commerce\Modules\Media\Http\MediaVariantController($service);

        $response = $controller('cache/card-g1/phones/e.avif');
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertSame('max-age=300, public', $response->headers->get('Cache-Control'), 'short cache: the AVIF replaces it later');
        self::assertSame(1, $service->queueLength());
        self::assertFileDoesNotExist($this->dir . '/public/media/cache/card-g1/phones/e.avif');
    }

    #[RequiresFunction('imageavif')]
    public function testOnceTheAvifIsMadeThePageGetsItWithALongCache(): void
    {
        $this->original('phones/e', 1600, 800);
        $service = $this->service(new VipsImageEncoder($this->dir, 'gd'), ['format' => 'avif_webp']);
        $controller = new \Commerce\Modules\Media\Http\MediaVariantController($service);
        $controller('cache/card-g1/phones/e.avif');

        $service->processQueue();
        $after = $controller('cache/card-g1/phones/e.avif');
        self::assertSame('image/avif', $after->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $after->headers->get('Cache-Control'));
    }
}
