<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Normalises editor HTML into plain text for <meta name="description"> and Open Graph tags:
 * tags stripped, entities decoded, whitespace collapsed, cut on a word boundary.
 */
final class SeoTextTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('seo_text', $this->text(...)),
            // JSON for <script type="application/ld+json">: <, >, & and quotes are \u-escaped, so data can never
            // close the script element; the output is therefore safe to print without |raw.
            new TwigFilter('json_ld', $this->jsonLd(...), ['is_safe' => ['html']]),
        ];
    }

    public function text(mixed $value, int $maxLength = 160): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br', '</p>', '</li>'], [' <br', ' </p>', ' </li>'], (string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($maxLength < 1 || mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }
        $cut = mb_substr($text, 0, $maxLength - 1, 'UTF-8');
        $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($space !== false && $space > (int) ($maxLength * 0.6)) {
            $cut = mb_substr($cut, 0, $space, 'UTF-8');
        }

        return rtrim($cut, " ,.;:-—") . '…';
    }

    public function jsonLd(mixed $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
