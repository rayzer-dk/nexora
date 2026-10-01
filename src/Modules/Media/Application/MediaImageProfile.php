<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

/**
 * Image processing profile of a store: which derivative files an uploaded picture gets.
 *
 * The profile is one small payload: output format, responsive widths, a capped full-size copy, quality, whether the
 * untouched original is kept next to the derivatives and whether one JPEG copy is written for e-mails, feeds and very
 * old browsers. The "Simple" mode of the media settings only picks a preset; "Advanced" edits the fields directly.
 */
final class MediaImageProfile
{
    public const FORMATS = ['original', 'webp', 'avif', 'jpeg', 'png'];
    public const WIDTHS = [320, 640, 960, 1280, 1920];
    /** Derivatives are never wider than this; bigger sources are scaled down (the untouched original is kept separately). */
    public const MAX_DERIVATIVE_WIDTH = 1920;
    public const PRESETS = ['recommended', 'compatible', 'modern', 'custom'];

    /** @var array{format:string,widths:list<int>,include_original:bool,quality:int,keep_source:bool,jpeg_fallback:bool} */
    public const RECOMMENDED = ['format' => 'webp', 'widths' => [640, 960, 1280], 'include_original' => true, 'quality' => 82, 'keep_source' => true, 'jpeg_fallback' => false];

    /**
     * Profile of a named preset; "custom" takes the given advanced input.
     *
     * @param array<string,mixed> $custom
     * @return array{format:string,widths:list<int>,include_original:bool,quality:int,keep_source:bool,jpeg_fallback:bool}
     */
    public static function fromPreset(string $preset, array $custom = []): array
    {
        return match ($preset) {
            'recommended' => self::RECOMMENDED,
            'compatible' => ['jpeg_fallback' => true] + self::RECOMMENDED,
            'modern' => ['format' => 'avif', 'quality' => 60] + self::RECOMMENDED,
            default => self::normalize($custom),
        };
    }

    /** Which preset a saved profile equals, otherwise "custom". @param array<string,mixed> $profile */
    public static function detect(array $profile): string
    {
        $profile = self::normalize($profile);
        foreach (['recommended', 'compatible', 'modern'] as $preset) {
            if (self::fromPreset($preset) == $profile) {
                return $preset;
            }
        }

        return 'custom';
    }

    /**
     * Clamps any stored or submitted input to a valid profile. A missing payload means "recommended".
     *
     * @param array<string,mixed> $input
     * @return array{format:string,widths:list<int>,include_original:bool,quality:int,keep_source:bool,jpeg_fallback:bool}
     */
    public static function normalize(array $input): array
    {
        if ($input === []) {
            return self::RECOMMENDED;
        }
        $format = strtolower(trim((string) ($input['format'] ?? 'webp')));
        if (!in_array($format, self::FORMATS, true)) {
            $format = 'webp';
        }
        $widths = array_values(array_unique(array_filter(array_map('intval', (array) ($input['widths'] ?? self::RECOMMENDED['widths'])), static fn (int $w): bool => in_array($w, self::WIDTHS, true))));
        sort($widths);

        return [
            'format' => $format,
            'widths' => $widths,
            'include_original' => (bool) ($input['include_original'] ?? true),
            'quality' => max(35, min(95, (int) ($input['quality'] ?? 82))),
            // Stores saved before 3.23 never kept the original file, so a saved payload without the flag stays as it was.
            'keep_source' => (bool) ($input['keep_source'] ?? false),
            'jpeg_fallback' => (bool) ($input['jpeg_fallback'] ?? false),
        ];
    }
}
