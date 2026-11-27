<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Recovery\RecoverySnapshotService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class RecoveryMediaCacheTest extends TestCase
{
    public function testMediaBackupKeepsOriginalsButSkipsGeneratedSizes(): void
    {
        $dir = sys_get_temp_dir() . '/nx-media-backup-' . bin2hex(random_bytes(5));
        mkdir($dir . '/media/cache/card-g1', 0777, true);
        mkdir($dir . '/media/products/cache', 0777, true);
        file_put_contents($dir . '/media/products/original.jpg', 'original');
        file_put_contents($dir . '/media/products/cache/keep.jpg', 'another original');
        file_put_contents($dir . '/media/cache/card-g1/generated.webp', 'generated');
        $zip = new ZipArchive();
        try {
            self::assertTrue($zip->open($dir . '/backup.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $service = (new \ReflectionClass(RecoverySnapshotService::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod($service, 'addDirectory');
            $files = [];
            $method->invokeArgs($service, [$zip, $dir . '/media', 'files/public/media', &$files]);
            self::assertTrue($zip->close());
            self::assertTrue($zip->open($dir . '/backup.zip'));
            self::assertNotFalse($zip->locateName('files/public/media/'));
            self::assertNotFalse($zip->locateName('files/public/media/products/original.jpg'));
            self::assertNotFalse($zip->locateName('files/public/media/products/cache/keep.jpg'));
            self::assertFalse($zip->locateName('files/public/media/cache/card-g1/generated.webp'));
            self::assertCount(2, $files);
            $zip->close();
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        }
    }
}
