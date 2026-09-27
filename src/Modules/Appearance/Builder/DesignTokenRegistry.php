<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Builder;

final class DesignTokenRegistry
{
    /** @return array<string,string> */
    public function defaults(): array
    {
        return [
            'color.primary' => '#0B63F6', 'color.accent' => '#B90303', 'color.background' => '#FFFFFF',
            'color.surface' => '#FFFFFF', 'color.text' => '#111827', 'color.muted' => '#667085',
            'color.border' => '#E5E7EB', 'radius.sm' => '8px', 'radius.md' => '14px', 'radius.lg' => '22px',
            'shadow.card' => '0 8px 30px rgba(15,23,42,.08)', 'space.1' => '4px', 'space.2' => '8px',
            'space.3' => '12px', 'space.4' => '16px', 'space.6' => '24px', 'space.8' => '32px',
            'content.max' => '1408px', 'font.body' => 'ui-sans-serif, system-ui, sans-serif',
            'font.size.base' => '16px', 'button.height' => '44px', 'input.height' => '44px',
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

    /** @param array<string,string> $tokens */
    public function cssVariables(array $tokens): string
    {
        $parts = [];
        foreach ($this->merge($tokens) as $key => $value) {
            $parts[] = '--mc-' . str_replace('.', '-', $key) . ':' . $value;
        }
        return implode(';', $parts);
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
        if (str_contains($key, 'radius') || str_contains($key, 'space') || str_contains($key, 'height') || $key === 'content.max' || $key === 'font.size.base') {
            return preg_match('/^\d+(?:\.\d+)?(?:px|rem|em|%)$/D', $value) === 1;
        }
        return preg_match('/^[A-Za-z0-9 ,."\'()\-\/#:%]+$/D', $value) === 1;
    }
}
