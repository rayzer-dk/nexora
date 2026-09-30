<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Modules\Media\Application\HeicDecoder;
use PHPUnit\Framework\TestCase;

final class HeicDecoderTest extends TestCase
{
    public function testHeaderDetection(): void
    {
        self::assertTrue(HeicDecoder::looksLikeHeic("\x00\x00\x00\x1cftypheic\x00\x00\x00\x00"));
        self::assertTrue(HeicDecoder::looksLikeHeic("\x00\x00\x00\x18ftypmif1\x00\x00\x00\x00"));
        self::assertFalse(HeicDecoder::looksLikeHeic("\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00"));
        self::assertFalse(HeicDecoder::looksLikeHeic("\x00\x00\x00\x1cftypavif\x00\x00\x00\x00"));
        self::assertFalse(HeicDecoder::looksLikeHeic('short'));
    }

    public function testDecoderProducesJpegAndDetectsHeicFiles(): void
    {
        $dir = sys_get_temp_dir() . '/heic-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $input = $dir . '/in.heic';
        file_put_contents($input, "\x00\x00\x00\x1cftypheic\x00\x00\x00\x00");
        // a stand-in for Imagick: returns a real 2x2 JPEG
        $decoder = new HeicDecoder(static function (string $path): string {
            ob_start();
            imagejpeg(imagecreatetruecolor(2, 2));
            return (string) ob_get_clean();
        });
        self::assertTrue($decoder->available());
        self::assertTrue($decoder->isHeicFile($input));
        self::assertSame([2, 2], array_slice((array) getimagesizefromstring($decoder->toJpeg($input)), 0, 2));

        unlink($input);
        rmdir($dir);
    }

    public function testEmptyDecodeIsReportedByCode(): void
    {
        $decoder = new HeicDecoder(static fn (string $path): string => '');
        $this->expectExceptionMessage('heic_decode_failed');
        $decoder->toJpeg('/tmp/whatever.heic');
    }

    public function testMissingDecoderIsReportedByCode(): void
    {
        if (class_exists(\Imagick::class) && in_array('HEIC', array_map('strtoupper', \Imagick::queryFormats('HEIC')), true)) {
            self::markTestSkipped('This machine can decode HEIC.');
        }
        $decoder = new HeicDecoder();
        self::assertFalse($decoder->available());
        $this->expectExceptionMessage('heic_decoder_missing');
        $decoder->toJpeg('/tmp/whatever.heic');
    }
}
