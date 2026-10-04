<?php

declare(strict_types=1);

namespace Commerce\Modules\Notification\Domain;

/**
 * Counts what one SMS holds. A text that fits the GSM 7-bit alphabet (Latin letters, digits, common punctuation)
 * takes 160 characters in one SMS and 153 per part of a long one; any other character (Cyrillic, most accents, emoji)
 * switches the whole message to UCS-2: 70 characters in one SMS and 67 per part. The admin counter in
 * assets/storefront/admin-runtime.js repeats this rule; keep them equal.
 */
final class SmsText
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    /** @return array{encoding:string,units:int,segments:int,single_limit:int,part_limit:int} */
    public static function analyze(string $text): array
    {
        $gsmUnits = 0;
        $gsm = true;
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if (str_contains(self::GSM_BASIC, $char)) {
                ++$gsmUnits;
            } elseif (str_contains(self::GSM_EXTENDED, $char)) {
                $gsmUnits += 2;
            } else {
                $gsm = false;
                break;
            }
        }
        if ($gsm) {
            return ['encoding' => 'gsm7', 'units' => $gsmUnits, 'segments' => $gsmUnits === 0 ? 0 : ($gsmUnits <= 160 ? 1 : (int) ceil($gsmUnits / 153)), 'single_limit' => 160, 'part_limit' => 153];
        }
        $units = intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);

        return ['encoding' => 'ucs2', 'units' => $units, 'segments' => $units <= 70 ? 1 : (int) ceil($units / 67), 'single_limit' => 70, 'part_limit' => 67];
    }
}
