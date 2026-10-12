<?php

declare(strict_types=1);

namespace Commerce\Modules\OrderDocument\Application;

/** A Code 128 (set B) barcode as an inline SVG, for waybill stickers. Printable ASCII only; no library needed. */
final class Code128
{
    /** Element widths (bar, space, bar, space, bar, space) of the symbols 0..105, then the stop symbol (7 elements). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];
    private const STOP = '2331112';
    private const START_B = 104;

    /** @return list<int> the symbol values of the text including start, check digit and stop */
    public static function symbols(string $text): array
    {
        $text = self::clean($text);
        $values = [self::START_B];
        $sum = self::START_B;
        foreach (str_split($text) as $i => $char) {
            $value = ord($char) - 32;
            $values[] = $value;
            $sum += $value * ($i + 1);
        }
        $values[] = $sum % 103;
        $values[] = 106;

        return $values;
    }

    /** Characters Code 128 set B cannot carry are dropped; an empty text becomes a dash. */
    public static function clean(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        $text = mb_substr($text, 0, 40);

        return $text === '' ? '-' : $text;
    }

    /** The SVG of the barcode; the width follows the container, the height is given. */
    public static function svg(string $text, int $height = 50): string
    {
        $x = 10; // quiet zone, in modules
        $rects = '';
        foreach (self::symbols($text) as $value) {
            $pattern = $value === 106 ? self::STOP : self::PATTERNS[$value];
            foreach (str_split($pattern) as $i => $width) {
                $w = (int) $width;
                if ($i % 2 === 0) {
                    $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
                }
                $x += $w;
            }
        }
        $total = $x + 10;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . htmlspecialchars(self::clean($text), ENT_QUOTES) . '" fill="#000">' . $rects . '</svg>';
    }
}
