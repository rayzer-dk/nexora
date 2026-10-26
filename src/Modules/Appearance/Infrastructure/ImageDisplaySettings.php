<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Infrastructure;

/**
 * How product photos sit in their frame. The photo file is never touched: the frame and the scaling are drawn by CSS, so
 * changing a setting needs no new cache files. "fit" is contain (the whole photo, no crop) or cover (fills the frame, crops);
 * "upscale" is how far a photo smaller than its frame may be enlarged (1x = never, unlimited = fill the frame). Cards, the
 * main gallery photo and the thumbnails take the common values unless they have their own.
 */
final class ImageDisplaySettings
{
    public const SURFACES = ['cards', 'gallery', 'thumbs'];
    public const BACKGROUNDS = ['none', 'white', 'dark'];
    public const FITS = ['contain', 'cover'];
    public const UPSCALES = ['none', '2', '3', 'unlimited'];
    /** Enlargement factor used for "unlimited": larger than any frame. */
    private const UNLIMITED = 1000;

    /** @return array<string,string> */
    public static function defaults(): array
    {
        $defaults = ['image_background' => 'none', 'image_fit' => 'contain', 'image_upscale' => '3'];
        foreach (self::SURFACES as $surface) {
            $defaults['image_fit_' . $surface] = 'inherit';
            $defaults['image_upscale_' . $surface] = 'inherit';
        }

        return $defaults;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public static function normalize(array $input): array
    {
        $defaults = self::defaults();
        $choice = static fn (mixed $value, array $allowed, string $fallback): string => in_array((string) $value, $allowed, true) ? (string) $value : $fallback;
        $out = [
            'image_background' => $choice($input['image_background'] ?? $defaults['image_background'], self::BACKGROUNDS, $defaults['image_background']),
            'image_fit' => $choice($input['image_fit'] ?? $defaults['image_fit'], self::FITS, $defaults['image_fit']),
            'image_upscale' => $choice($input['image_upscale'] ?? $defaults['image_upscale'], self::UPSCALES, $defaults['image_upscale']),
        ];
        foreach (self::SURFACES as $surface) {
            $out['image_fit_' . $surface] = $choice($input['image_fit_' . $surface] ?? 'inherit', ['inherit', ...self::FITS], 'inherit');
            $out['image_upscale_' . $surface] = $choice($input['image_upscale_' . $surface] ?? 'inherit', ['inherit', ...self::UPSCALES], 'inherit');
        }

        return $out;
    }

    /** Custom properties for the <body>: --mc-fit-, --mc-up- (and --mc-pad- for cover) per surface, with "inherit" already replaced by the common value. @param array<string,mixed> $display */
    public static function style(array $display): string
    {
        $display = self::normalize($display);
        $parts = [];
        foreach (self::SURFACES as $surface) {
            $fit = $display['image_fit_' . $surface] === 'inherit' ? $display['image_fit'] : $display['image_fit_' . $surface];
            $upscale = $display['image_upscale_' . $surface] === 'inherit' ? $display['image_upscale'] : $display['image_upscale_' . $surface];
            $parts[] = '--mc-fit-' . $surface . ':' . $fit;
            // Cover fills the frame, so there is no enlargement limit and no inner margin.
            $parts[] = '--mc-up-' . $surface . ':' . ($fit === 'cover' ? (string) self::UNLIMITED : match ($upscale) {
                'none' => '1',
                'unlimited' => (string) self::UNLIMITED,
                default => $upscale,
            });
            if ($fit === 'cover') {
                $parts[] = '--mc-pad-' . $surface . ':0';
            }
        }

        return implode(';', $parts);
    }
}
