<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the home page does with the settings of a builder block: which devices show it, its background, text colour,
 * padding and margin, and the grouping of neighbouring banners into one row. Every value is checked against a
 * strict pattern, so a block can never put arbitrary CSS or a script URL into the page.
 */
final class HomeBlockExtension extends AbstractExtension
{
    private const COLOR = '/^(#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}(?:\s*,\s*\d{1,3}){2}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)|transparent)$/D';
    private const GRADIENT = '/^linear-gradient\(\s*(?:\d{1,3}deg\s*,\s*)?(?:#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,]+\))(?:\s+\d{1,3}%)?(?:\s*,\s*(?:#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,]+\))(?:\s+\d{1,3}%)?){1,3}\s*\)$/D';
    private const SPACING = '/^(?:-?\d{1,3}(?:\.\d+)?(?:px|rem|em|%|vh)?\s*){1,4}$/D';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('home_block_attrs', [$this, 'attrs']),
            new TwigFunction('home_blocks_grouped', [$this, 'grouped']),
            new TwigFunction('safe_media_url', [$this, 'mediaUrl']),
            new TwigFunction('block_color', [$this, 'color']),
            new TwigFunction('block_background', [$this, 'background']),
        ];
    }

    /**
     * @param array<string,mixed> $block a builder block (props, style, visibility)
     * @return array{class:string,style:string}
     */
    public function attrs(array $block, bool $withStyle = true): array
    {
        $visibility = is_array($block['visibility'] ?? null) ? $block['visibility'] : [];
        $classes = [];
        foreach (['desktop', 'tablet', 'mobile'] as $device) {
            if (($visibility[$device] ?? true) === false) {
                $classes[] = 'vis-hide-' . $device;
            }
        }
        $style = [];
        $given = is_array($block['style'] ?? null) ? $block['style'] : [];
        if ($withStyle) {
            $background = $this->background($given['background'] ?? '');
            if ($background !== '') {
                $style[] = 'background:' . $background;
            }
            $color = $this->color($given['color'] ?? '');
            if ($color !== '') {
                $style[] = 'color:' . $color;
            }
            foreach (['padding', 'margin'] as $key) {
                $value = trim((string) ($given[$key] ?? ''));
                if ($value !== '' && preg_match(self::SPACING, $value) === 1) {
                    $style[] = $key . ':' . preg_replace('/(?<=\d)\s+(?=-?\d)/', ' ', $value);
                }
            }
            $align = (string) ($given['text_align'] ?? '');
            if (in_array($align, ['left', 'center', 'right'], true)) {
                $style[] = 'text-align:' . $align;
            }
        }

        return ['class' => implode(' ', $classes), 'style' => implode(';', $style)];
    }

    /** A background the builder accepts: a colour or a simple linear-gradient of colours, or an empty string. */
    public function background(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' && (preg_match(self::COLOR, $value) === 1 || preg_match(self::GRADIENT, $value) === 1) ? $value : '';
    }

    /** A colour the builder accepts (hex, rgb(a), transparent) or an empty string. */
    public function color(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match(self::COLOR, $value) === 1 ? $value : '';
    }

    /** Local /media path or https URL of an image or video; anything else (javascript:, data:, //host) becomes empty. */
    public function mediaUrl(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 500 || preg_match('/[\x00-\x20"\'<>\\\\]/', $value) === 1) {
            return '';
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }

        return preg_match('~^https://[A-Za-z0-9.-]+(?::\d+)?(?:/[^\s]*)?$~D', $value) === 1 ? $value : '';
    }

    /**
     * Neighbouring enabled banners become one row, so two half-width banners sit side by side.
     *
     * @param array<int,mixed> $blocks
     * @return list<array<string,mixed>>
     */
    public function grouped(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $active = ($block['enabled'] ?? true) !== false && !($block['missing_extension'] ?? false);
            if (($block['component'] ?? '') === 'banner' && $active) {
                $last = count($out) - 1;
                if ($last >= 0 && ($out[$last]['component'] ?? '') === 'banner_row') {
                    $out[$last]['items'][] = $block;
                } else {
                    $out[] = ['id' => (string) ($block['id'] ?? 'banner'), 'component' => 'banner_row', 'enabled' => true, 'items' => [$block]];
                }
                continue;
            }
            $out[] = $block;
        }

        return $out;
    }
}
