<?php

declare(strict_types=1);

namespace Commerce\Core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Picks a readable text colour (WCAG contrast) for merchant-defined brand colours used as backgrounds. */
final class ColorContrastTwigExtension extends AbstractExtension
{
    public const LIGHT = '#ffffff';
    public const DARK = '#0b1220';

    public function getFunctions(): array
    {
        return [new TwigFunction('contrast_color', [$this, 'contrastColor'])];
    }

    public function contrastColor(mixed $background): string
    {
        $rgb = self::parse($background);
        if ($rgb === null) {
            return self::LIGHT;
        }
        $l = self::luminance($rgb);
        $withLight = 1.05 / ($l + 0.05);
        $withDark = ($l + 0.05) / (self::luminance([11, 18, 32]) + 0.05);

        return $withLight >= $withDark || $withLight >= 4.5 ? self::LIGHT : self::DARK;
    }

    /** @return array{int,int,int}|null */
    private static function parse(mixed $value): ?array
    {
        if (!is_string($value) || preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/iD', trim($value), $m) !== 1) {
            return null;
        }
        $hex = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];

        return [(int) hexdec(substr((string) $hex, 0, 2)), (int) hexdec(substr((string) $hex, 2, 2)), (int) hexdec(substr((string) $hex, 4, 2))];
    }

    /** @param array{int,int,int} $rgb */
    private static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(static function (int $c): float {
            $s = $c / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
