<?php

declare(strict_types=1);

namespace Commerce\Core\Text;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Attributes the rich-text editor writes that the stock sanitizer does not know how to check:
 *  - style: only a short allow-list of declarations (colour, background, font size/family, line height, alignment) with strict values;
 *  - id: a plain anchor name;
 *  - iframe src: only an embedded YouTube or Vimeo player over HTTPS.
 */
final class RichTextAttributeSanitizer implements AttributeSanitizerInterface
{
    private const FONT_FAMILIES = ['Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Courier New', 'Verdana', 'Tahoma', 'Trebuchet MS', 'system-ui', 'serif', 'sans-serif', 'monospace'];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style', 'id', 'src', 'class', 'data-icon'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ($attribute === 'src') {
            return $element === 'iframe' ? self::embedUrl($value) : $value;
        }
        if ($attribute === 'data-icon') {
            return $element === 'span' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) === 1 ? $value : null;
        }
        if ($attribute === 'class') {
            // The inline icon marker is the only class a span may carry; an iframe keeps its player classes.
            if ($element === 'span') {
                return $value === 'mc-inline-icon' ? $value : null;
            }

            return preg_match('/^[A-Za-z0-9 _-]{1,80}$/D', $value) === 1 ? $value : null;
        }
        if ($attribute === 'id') {
            return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $value) === 1 ? $value : null;
        }

        return self::style($value);
    }

    public static function embedUrl(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('#^https://(?:www\.)?(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_-]{6,20})(?:\?[A-Za-z0-9=&_%.-]{0,120})?$#D', $url, $m) === 1) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }
        if (preg_match('#^https://player\.vimeo\.com/video/([0-9]{4,12})(?:\?[A-Za-z0-9=&_%.-]{0,120})?$#D', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return null;
    }

    public static function style(string $style): ?string
    {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
            $ok = match ($property) {
                'color', 'background-color' => preg_match('/^(?:#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)|[a-zA-Z]{3,20})$/D', $value) === 1,
                'font-size' => preg_match('/^\d{1,3}(?:\.\d{1,2})?(?:px|pt|em|rem|%)$/D', $value) === 1,
                'line-height' => preg_match('/^\d{1,2}(?:\.\d{1,2})?(?:px|em|rem|%)?$/D', $value) === 1,
                'text-align' => in_array($value, ['left', 'right', 'center', 'justify'], true),
                'font-family' => self::fontFamily($value),
                default => false,
            };
            if ($ok) {
                $kept[] = $property . ': ' . $value;
            }
        }

        return $kept === [] ? null : implode('; ', $kept) . ';';
    }

    private static function fontFamily(string $value): bool
    {
        foreach (explode(',', $value) as $part) {
            if (!in_array(trim($part, " \t\"'"), self::FONT_FAMILIES, true)) {
                return false;
            }
        }

        return true;
    }
}
