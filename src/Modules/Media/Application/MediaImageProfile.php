<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

/**
 * Image processing profile of a store.
 *
 * The ORIGINAL a person uploads is stored as it is (upright, without camera metadata, at most ORIGINAL_MAX px on the long
 * side) in its library folder: public/media/<folder>/<name>.<ext>. Every other size and format is a cache file made from
 * the original: public/media/cache/<preset>-g<N>/<folder>/<name>.<webp|avif|jpg>. The main product photo gets its cache
 * files right away, every other photo on the first request; the cache can be deleted at any time and is made again.
 *
 * "format" is what the storefront delivers: webp (WebP only), avif_webp (AVIF with a WebP fallback) or jpeg.
 * "generation" is part of every cache path. Changing a preset width or a quality raises it, so new pages use new files
 * while the files of older generations stop being referenced and are removed by the garbage collector.
 */
final class MediaImageProfile
{
    public const FORMATS = ['webp', 'avif_webp', 'jpeg'];
    /** The stored original is never larger than this on its long side; bigger uploads are scaled down. */
    public const ORIGINAL_MAX = 2560;
    /** Preset name => allowed widths (the first one is the default). A closed set: nobody can ask for any other size. */
    public const PRESET_WIDTHS = [
        'thumb' => [160, 120, 240],
        'card' => [480, 360, 640],
        'product' => [960, 720, 1200],
        'zoom' => [1600, 1280, 1920],
    ];
    /** Presets made at once for the primary photo of a product; everything else is lazy. */
    public const EAGER_PRESETS = ['thumb', 'card', 'product'];
    public const PRESETS = ['recommended', 'compatible', 'modern', 'custom'];

    /** @var array{format:string,quality:int,avif_quality:int,presets:array<string,int>,generation:int} */
    public const RECOMMENDED = ['format' => 'webp', 'quality' => 82, 'avif_quality' => 60, 'presets' => ['thumb' => 160, 'card' => 480, 'product' => 960, 'zoom' => 1600], 'generation' => 1];

    /**
     * Profile of a named preset; "custom" takes the given advanced input.
     *
     * @param array<string,mixed> $custom
     * @return array{format:string,quality:int,avif_quality:int,presets:array<string,int>,generation:int}
     */
    public static function fromPreset(string $preset, array $custom = []): array
    {
        return match ($preset) {
            'recommended' => self::RECOMMENDED,
            'compatible' => ['format' => 'jpeg', 'quality' => 85] + self::RECOMMENDED,
            'modern' => ['format' => 'avif_webp'] + self::RECOMMENDED,
            default => self::normalize($custom),
        };
    }

    /** Which preset a saved profile equals, otherwise "custom". @param array<string,mixed> $profile */
    public static function detect(array $profile): string
    {
        $profile = self::comparable(self::normalize($profile));
        foreach (['recommended', 'compatible', 'modern'] as $preset) {
            if (self::comparable(self::fromPreset($preset)) == $profile) {
                return $preset;
            }
        }

        return 'custom';
    }

    /** File extension of the main (fallback) delivery format. */
    public static function extension(string $format): string
    {
        return $format === 'jpeg' ? 'jpg' : 'webp';
    }

    /**
     * Clamps any stored or submitted input to a valid profile. A missing payload means "recommended".
     *
     * @param array<string,mixed> $input
     * @return array{format:string,quality:int,avif_quality:int,presets:array<string,int>,generation:int}
     */
    public static function normalize(array $input): array
    {
        if ($input === []) {
            return self::RECOMMENDED;
        }
        $format = strtolower(trim((string) ($input['format'] ?? 'webp')));
        if ($format === 'avif') {
            $format = 'avif_webp'; // older profiles
        }
        if (!in_array($format, self::FORMATS, true)) {
            $format = 'webp';
        }
        $given = is_array($input['presets'] ?? null) ? $input['presets'] : [];
        $presets = [];
        foreach (self::PRESET_WIDTHS as $name => $allowed) {
            $width = (int) ($given[$name] ?? $allowed[0]);
            $presets[$name] = in_array($width, $allowed, true) ? $width : $allowed[0];
        }

        return [
            'format' => $format,
            'quality' => max(35, min(95, (int) ($input['quality'] ?? 82))),
            'avif_quality' => max(30, min(90, (int) ($input['avif_quality'] ?? 60))),
            'presets' => $presets,
            'generation' => max(1, min(9999, (int) ($input['generation'] ?? 1))),
        ];
    }

    /**
     * The profile to save: the generation goes up by one when a preset width or a quality changed, so the old cache
     * files are no longer referenced (and can be collected) while the new ones are made on demand.
     *
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $next
     * @return array{format:string,quality:int,avif_quality:int,presets:array<string,int>,generation:int}
     */
    public static function withGeneration(array $previous, array $next): array
    {
        $previous = self::normalize($previous);
        $next = self::normalize($next);
        $changed = $previous['presets'] !== $next['presets'] || $previous['quality'] !== $next['quality'] || $previous['avif_quality'] !== $next['avif_quality'];
        $next['generation'] = $changed ? $previous['generation'] + 1 : $previous['generation'];

        return $next;
    }

    /** @param array{format:string,quality:int,avif_quality:int,presets:array<string,int>,generation:int} $profile @return array<string,mixed> */
    private static function comparable(array $profile): array
    {
        unset($profile['generation']);

        return $profile;
    }
}
