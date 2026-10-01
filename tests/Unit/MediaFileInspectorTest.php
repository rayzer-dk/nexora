<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Media\Application\MediaFileInspector;
use Commerce\Modules\Media\Application\MediaImageProfile;
use Commerce\Modules\Media\Application\MediaVariantService;
use PHPUnit\Framework\TestCase;

final class MediaFileInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nx-inspect-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public/media/catalog/ab', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function inspector(int $generation): MediaFileInspector
    {
        $variants = (new \ReflectionClass(MediaVariantService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($variants, 'projectDir'))->setValue($variants, $this->dir);
        (new \ReflectionProperty($variants, 'profile'))->setValue($variants, ['generation' => $generation] + MediaImageProfile::RECOMMENDED);

        return new MediaFileInspector($variants, $this->dir);
    }

    private function image(string $name, int $width): void
    {
        $img = imagecreatetruecolor($width, (int) ($width / 2));
        imagewebp($img, $this->dir . '/public/media/catalog/ab/' . $name, 80);
    }

    public function testListsSourceMasterSizesAndWhatIsStillToBeMade(): void
    {
        $this->image('f00.webp', 800);
        $this->image('f00.card-g2.webp', 480);
        $this->image('f00.card-g1.webp', 400); // outdated generation
        file_put_contents($this->dir . '/public/media/catalog/ab/f00.source.jpeg', str_repeat('x', 100));
        $this->image('other.webp', 100); // another picture must not be listed

        $r = $this->inspector(2)->inspect('catalog/ab/f00.webp');
        $roles = array_map(static fn (array $f): string => $f['role'] . ':' . $f['preset'] . ':' . ($f['current'] ? 'now' : 'old'), $r['files']);
        self::assertSame(['source::now', 'master::now', 'size:card:old', 'size:card:now'], $roles);
        self::assertSame(['thumb', 'product', 'zoom'], array_column($r['pending'], 'preset'));
        self::assertSame(800, $r['files'][1]['width']);
        self::assertGreaterThan(100, $r['bytes']);
    }

    public function testForgetSizesKeepsMasterAndSource(): void
    {
        $this->image('f00.webp', 800);
        $this->image('f00.card-g2.webp', 480);
        $this->image('f00.thumb-g1.webp', 160);
        file_put_contents($this->dir . '/public/media/catalog/ab/f00.source.jpeg', 'x');

        self::assertSame(2, $this->inspector(2)->forgetSizes('catalog/ab/f00.webp'));
        self::assertFileExists($this->dir . '/public/media/catalog/ab/f00.webp');
        self::assertFileExists($this->dir . '/public/media/catalog/ab/f00.source.jpeg');
        self::assertFileDoesNotExist($this->dir . '/public/media/catalog/ab/f00.card-g2.webp');
    }
}
