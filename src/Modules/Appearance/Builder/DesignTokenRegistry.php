<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

final class DesignTokenRegistry
{
    /** @return array<string,string> */
    public function defaults(): array
    {
        return [
            'color.primary' => '#0B63F6',
            'color.primary.hover' => '#084FC5',
            'color.primary.contrast' => '#FFFFFF',
            'color.accent' => '#FF7A1A',
            'color.success' => '#16A364',
            'color.background' => '#FFFFFF',
            'color.surface' => '#FFFFFF',
            'color.surface.subtle' => '#F6F8FB',
            'color.text' => '#111827',
            'color.muted' => '#667085',
            'color.border' => '#E1E7EF',
            'color.focus' => '#8BB8FF',
            'radius.xs' => '6px',
            'radius.sm' => '8px',
            'radius.md' => '12px',
            'radius.lg' => '18px',
            'radius.xl' => '24px',
            'radius.full' => '999px',
            'space.0' => '0px',
            'space.1' => '4px',
            'space.2' => '8px',
            'space.3' => '12px',
            'space.4' => '16px',
            'space.5' => '20px',
            'space.6' => '24px',
            'space.8' => '32px',
            'space.10' => '40px',
            'space.12' => '48px',
            'space.16' => '64px',
            'space.20' => '80px',
            'space.24' => '96px',
            'font.body' => 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI Variable", "Segoe UI", Roboto, Arial, sans-serif',
            'font.size.xs' => '12px',
            'font.size.sm' => '14px',
            'font.size.base' => '16px',
            'font.size.lg' => '18px',
            'font.size.xl' => '20px',
            'font.size.2xl' => '24px',
            'font.size.3xl' => '30px',
            'font.size.4xl' => '38px',
            'font.size.5xl' => '48px',
            'line.height.tight' => '1.2',
            'line.height.heading' => '1.25',
            'line.height.body' => '1.5',
            'line.height.relaxed' => '1.7',
            'control.height.sm' => '36px',
            'control.height.md' => '44px',
            'control.height.lg' => '52px',
            'control.height.xl' => '56px',
            'button.height' => '44px',
            'input.height' => '44px',
            'icon.size.sm' => '16px',
            'icon.size.md' => '20px',
            'icon.size.lg' => '24px',
            'icon.size.xl' => '28px',
            'shadow.none' => 'none',
            'shadow.sm' => '0 4px 14px rgba(15,23,42,.06)',
            'shadow.md' => '0 10px 30px rgba(15,23,42,.09)',
            'shadow.lg' => '0 20px 50px rgba(15,23,42,.13)',
            'shadow.card' => '0 10px 30px rgba(15,23,42,.08)',
            'breakpoint.sm' => '560px',
            'breakpoint.md' => '820px',
            'breakpoint.lg' => '1100px',
            'breakpoint.xl' => '1400px',
            'content.max' => '1408px',
            'z.base' => '1',
            'z.dropdown' => '200',
            'z.sticky' => '400',
            'z.header' => '600',
            'z.overlay' => '1200',
            'z.modal' => '1400',
            'z.toast' => '1600',
            'z.max' => '2147483000',
        ];
    }

    /** @param array<string,string> ...$layers @return array<string,string> */
    public function merge(array ...$layers): array
    {
        $tokens = $this->defaults();
        foreach ($layers as $layer) {
            foreach ($layer as $key => $value) {
                if (array_key_exists($key, $tokens) && $this->isSafe($key, $value)) {
                    $tokens[$key] = trim($value);
                }
            }
        }
        return $tokens;
    }

    /** @param array<string,mixed> $presentation @return array<string,string> */
    public function forPresentation(array $presentation): array
    {
        $theme = is_array($presentation['theme'] ?? null) ? $presentation['theme'] : [];
        $font = (string)($theme['font'] ?? 'system');
        $radius = max(4, min(32, (int)($theme['radius'] ?? 18)));
        $shadow = (string)($theme['shadow'] ?? 'medium');
        $shadowValue = match ($shadow) {
            'none' => 'none',
            'soft' => '0 4px 18px rgba(15,23,42,.06)',
            'strong' => '0 20px 54px rgba(15,23,42,.15)',
            default => '0 10px 30px rgba(15,23,42,.09)',
        };

        $primary = (string)($theme['primary'] ?? '#0B63F6');
        return $this->merge([
            'color.primary' => $primary,
            'color.primary.hover' => $this->shadeHex($primary, 0.82),
            'color.accent' => (string)($theme['accent'] ?? '#FF7A1A'),
            'color.success' => (string)($theme['success'] ?? '#16A364'),
            'color.surface' => (string)($theme['surface'] ?? '#FFFFFF'),
            'radius.lg' => $radius . 'px',
            'radius.xl' => min(40, $radius + 6) . 'px',
            'shadow.card' => $shadowValue,
            'content.max' => max(960, min(1680, (int)($theme['container'] ?? 1408))) . 'px',
            'font.body' => $this->fontStack($font),
        ]);
    }

    public function fontStack(string $font): string
    {
        return match ($font) {
            'inter' => '"Inter", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'manrope' => '"Manrope", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            default => 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI Variable", "Segoe UI", Roboto, Arial, sans-serif',
        };
    }

    /** @param array<string,string> $tokens */
    public function cssVariables(array $tokens): string
    {
        $parts = [];
        foreach ($this->merge($tokens) as $key => $value) {
            $parts[] = '--mc-' . str_replace('.', '-', $key) . ':' . $value;
        }
        return implode(';', $parts);
    }

    private function shadeHex(string $hex, float $factor): string
    {
        if (preg_match('/^#([0-9A-Fa-f]{6})$/D', $hex, $m) !== 1) {
            return '#084FC5';
        }
        $raw = $m[1];
        $parts = [hexdec(substr($raw, 0, 2)), hexdec(substr($raw, 2, 2)), hexdec(substr($raw, 4, 2))];
        return sprintf('#%02X%02X%02X', ...array_map(static fn (int $v): int => max(0, min(255, (int) round($v * $factor))), $parts));
    }

    private function isSafe(string $key, string $value): bool
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255 || preg_match('/[{}<>;]/', $value)) {
            return false;
        }
        if (str_starts_with($key, 'color.')) {
            return preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1;
        }
        if (str_starts_with($key, 'line.height.') || str_starts_with($key, 'z.')) {
            return preg_match('/^\d+(?:\.\d+)?$/D', $value) === 1;
        }
        if (str_contains($key, 'radius') || str_contains($key, 'space') || str_contains($key, 'height') || str_contains($key, 'size') || str_contains($key, 'breakpoint') || $key === 'content.max') {
            return preg_match('/^\d+(?:\.\d+)?(?:px|rem|em|%)$/D', $value) === 1;
        }
        return preg_match('/^[A-Za-z0-9 ,."\'()\-\/#:%]+$/D', $value) === 1;
    }
}
