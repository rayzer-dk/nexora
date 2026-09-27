<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Application;

use Commerce\Modules\Appearance\Domain\ThemeSettings;

final class ThemeCssVariablesBuilder
{
    public function build(ThemeSettings $settings): string
    {
        $safePrimary = $this->hex($settings->primary, '#0B63F6');
        $safeAccent = $this->hex($settings->accent, '#B90303');
        $safeRadius = $this->length($settings->surfaceRadius, '14px');
        $safeWidth = $this->length($settings->contentWidth, '1408px');

        return implode(';', [
            '--color-primary:' . $safePrimary,
            '--color-accent:' . $safeAccent,
            '--radius-ui:' . $safeRadius,
            '--content-max:' . $safeWidth,
            '--density:' . $this->density($settings->density),
            '--font-family:' . $this->fontStack($settings->fontStack),
        ]);
    }

    private function hex(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : $fallback;
    }

    private function length(string $value, string $fallback): string
    {
        return preg_match('/^\d+(?:\.\d+)?(?:px|rem|em)$/', $value) === 1 ? $value : $fallback;
    }

    private function fontStack(string $value): string
    {
        return preg_match('/^[A-Za-z0-9 ,\"\'-]+$/', $value) === 1 ? $value : 'ui-sans-serif, system-ui, sans-serif';
    }

    private function density(string $value): string
    {
        return match ($value) {
            'compact' => '0.88',
            'spacious' => '1.12',
            default => '1',
        };
    }
}
