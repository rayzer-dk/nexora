<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

/**
 * How a language is named to people: in its own words and without the country ("Українська", "English", "Русский"),
 * never as a locale code. The code ("uk-UA") is for programs: folders, URLs, the lang attribute.
 */
final class LanguageName
{
    /** "ru-RU" -> "Русский" from intl; the code only for a language intl does not know. */
    public static function native(string $code): string
    {
        $name = trim(\Locale::getDisplayLanguage($code, $code));
        if ($name === '' || strcasecmp($name, $code) === 0 || strcasecmp($name, explode('-', str_replace('_', '-', $code), 2)[0]) === 0) {
            return $code;
        }

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /** A stored native name ("English (United States)") without the country; the intl name when nothing is stored. */
    public static function plain(string $storedNative, string $code): string
    {
        $name = trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $storedNative));

        return $name !== '' ? $name : self::native($code);
    }

    /** @param iterable<string> $codes "en-US, de-DE" as people read it: "English, Deutsch". */
    public static function list(iterable $codes): string
    {
        $names = [];
        foreach ($codes as $code) {
            $names[] = self::native((string) $code);
        }

        return implode(', ', $names);
    }
}
