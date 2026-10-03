<?php

declare(strict_types=1);

namespace Commerce\Core\Text;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Attributes of an e-mail written in HTML by the shop owner: inline CSS from a fixed allow-list of layout and
 * typography properties (no url(), no expressions, no imports) and the plain table attributes e-mail clients rely on.
 */
final class EmailHtmlAttributeSanitizer implements AttributeSanitizerInterface
{
    private const COLOR = '/^(?:#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)|[a-zA-Z]{3,20})$/D';
    private const LENGTH = '/^(?:auto|0|-?\d{1,4}(?:\.\d{1,2})?(?:px|pt|em|rem|%)?)$/D';

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style', 'width', 'height', 'align', 'valign', 'bgcolor', 'cellpadding', 'cellspacing', 'border', 'colspan', 'rowspan', 'role'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $value = trim($value);

        return match ($attribute) {
            'style' => self::style($value),
            'width', 'height' => preg_match('/^\d{1,4}%?$/D', $value) === 1 ? $value : null,
            'align' => in_array($value, ['left', 'right', 'center'], true) ? $value : null,
            'valign' => in_array($value, ['top', 'middle', 'bottom'], true) ? $value : null,
            'bgcolor' => preg_match(self::COLOR, $value) === 1 ? $value : null,
            'cellpadding', 'cellspacing', 'border', 'colspan', 'rowspan' => preg_match('/^\d{1,3}$/D', $value) === 1 ? $value : null,
            'role' => $value === 'presentation' ? $value : null,
            default => null,
        };
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
            $ok = match (true) {
                in_array($property, ['color', 'background-color', 'border-color'], true) => preg_match(self::COLOR, $value) === 1,
                in_array($property, ['font-size', 'line-height', 'width', 'max-width', 'min-width', 'height', 'letter-spacing', 'border-radius', 'border-width'], true) => preg_match(self::LENGTH, $value) === 1,
                in_array($property, ['margin', 'padding', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right'], true) => preg_match('/^(?:(?:auto|0|-?\d{1,4}(?:\.\d{1,2})?(?:px|pt|em|rem|%)?)(?: |$)){1,4}$/D', $value . ' ') === 1,
                $property === 'text-align' => in_array($value, ['left', 'right', 'center', 'justify'], true),
                $property === 'vertical-align' => in_array($value, ['top', 'middle', 'bottom', 'baseline'], true),
                $property === 'font-weight' => preg_match('/^(?:normal|bold|[1-9]00)$/D', $value) === 1,
                $property === 'font-style' => in_array($value, ['normal', 'italic'], true),
                $property === 'text-decoration' => in_array($value, ['none', 'underline', 'line-through'], true),
                $property === 'text-transform' => in_array($value, ['none', 'uppercase', 'lowercase', 'capitalize'], true),
                $property === 'display' => in_array($value, ['block', 'inline', 'inline-block', 'none'], true),
                $property === 'border' || str_starts_with($property, 'border-') && in_array($property, ['border-top', 'border-bottom', 'border-left', 'border-right'], true) => preg_match('/^(?:none|\d{1,2}px (?:solid|dashed|dotted) (?:#[0-9a-fA-F]{3,8}|[a-zA-Z]{3,20}))$/D', $value) === 1,
                $property === 'border-collapse' => in_array($value, ['collapse', 'separate'], true),
                $property === 'font-family' => preg_match('/^[A-Za-z0-9 ,\'"-]{1,120}$/D', $value) === 1,
                default => false,
            };
            if ($ok) {
                $kept[] = $property . ': ' . $value;
            }
        }

        return $kept === [] ? null : implode('; ', $kept) . ';';
    }
}
