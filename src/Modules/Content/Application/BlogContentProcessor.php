<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Application;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** Pure helpers: reading time, plain excerpt and heading anchors + table of contents for article HTML. */
final class BlogContentProcessor
{
    private const WORDS_PER_MINUTE = 200;

    public static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>', '</h2>', '</h3>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function readingMinutes(string $html): int
    {
        $words = (int) preg_match_all('/[\p{L}\p{N}]+/u', self::plainText($html));
        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    public static function excerpt(string $html, int $length = 160): string
    {
        $text = self::plainText($html);
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space > (int) ($length * 0.6)) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }
        return rtrim($cut, " ,.;:-–—") . '…';
    }

    /**
     * Adds stable id attributes to h2/h3 headings (so they can be linked and shown in a table of contents).
     *
     * @return array{html:string,toc:list<array{id:string,text:string,level:int}>}
     */
    public static function withAnchors(string $html): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'toc' => []];
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $toc = [];
        $used = [];
        foreach ($xpath->query('//h2|//h3') ?: [] as $heading) {
            if (!$heading instanceof DOMElement) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', $heading->textContent));
            if ($text === '') {
                continue;
            }
            $base = self::anchor($text);
            $id = $base;
            for ($i = 2; isset($used[$id]); ++$i) {
                $id = $base . '-' . $i;
            }
            $used[$id] = true;
            $heading->setAttribute('id', $id);
            $toc[] = ['id' => $id, 'text' => $text, 'level' => $heading->tagName === 'h2' ? 2 : 3];
        }

        $root = $dom->getElementById('__root') ?? $xpath->query('//div[@id="__root"]')?->item(0);
        $out = '';
        if ($root instanceof DOMElement) {
            foreach ($root->childNodes as $child) {
                $out .= (string) $dom->saveHTML($child);
            }
        } else {
            $out = $html;
        }
        return ['html' => $out, 'toc' => $toc];
    }

    public static function anchor(string $text): string
    {
        $slug = mb_strtolower($text, 'UTF-8');
        $slug = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        $slug = trim($slug, '-');
        return $slug === '' ? 'section' : mb_substr($slug, 0, 60, 'UTF-8');
    }
}
