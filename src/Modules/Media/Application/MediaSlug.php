<?php

declare(strict_types=1);

namespace Commerce\Modules\Media\Application;

/** File and folder names of the media library: lower-case latin letters, digits and dashes, so every URL stays plain ASCII. */
final class MediaSlug
{
    public static function make(string $text, string $fallback = 'file', int $max = 60): string
    {
        $text = trim($text);
        if ($text !== '' && class_exists(\Transliterator::class)) {
            $converted = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($text);
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        } elseif ($text !== '' && function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }
        $slug = strtolower((string) preg_replace('~[^A-Za-z0-9]+~', '-', $text));
        $slug = trim(substr(trim($slug, '-'), 0, max(1, $max)), '-');

        return $slug === '' ? $fallback : $slug;
    }
}
