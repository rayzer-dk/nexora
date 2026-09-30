<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

use RuntimeException;

/**
 * Decodes HEIC/HEIF photos (iPhone, modern Android) to a JPEG byte string so the regular GD pipeline
 * can turn them into WebP/AVIF. GD cannot read HEIC itself, so the Imagick extension (built with libheif) does
 * the decoding in-process; the platform never starts external programs. Whether the server can decode HEIC is a
 * property of the server; {@see available()} tells the quality monitor whether the feature can work.
 */
final class HeicDecoder
{
    private const BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    /** @var (callable(string):string)|null */
    private $reader;

    /** @param (callable(string):string)|null $reader test seam: returns JPEG bytes for a file path instead of using Imagick */
    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader;
    }

    public static function looksLikeHeic(string $head): bool
    {
        return strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp' && in_array(strtolower(substr($head, 8, 4)), self::BRANDS, true);
    }

    public function isHeicFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 16);
        fclose($handle);
        return self::looksLikeHeic($head);
    }

    public function available(): bool
    {
        return $this->reader !== null || $this->imagickCanRead();
    }

    /** @return string JPEG bytes, auto-rotated and stripped of metadata */
    public function toJpeg(string $path): string
    {
        if ($this->reader !== null) {
            $blob = ($this->reader)($path);
            if ($blob === '') {
                throw new RuntimeException('heic_decode_failed');
            }
            return $blob;
        }
        if (!$this->imagickCanRead()) {
            throw new RuntimeException('heic_decoder_missing');
        }
        try {
            $image = new \Imagick($path . '[0]');
            $image->autoOrient();
            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(92);
            $blob = (string) $image->getImageBlob();
            $image->clear();
        } catch (\Throwable) {
            throw new RuntimeException('heic_decode_failed');
        }
        if ($blob === '') {
            throw new RuntimeException('heic_decode_failed');
        }
        return $blob;
    }

    private function imagickCanRead(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }
        try {
            return in_array('HEIC', array_map('strtoupper', \Imagick::queryFormats('HEIC')), true);
        } catch (\Throwable) {
            return false;
        }
    }
}
