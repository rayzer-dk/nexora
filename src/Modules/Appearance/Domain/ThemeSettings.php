<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Domain;

final readonly class ThemeSettings
{
    public function __construct(
        public ThemeMode $mode = ThemeMode::Auto,
        public string $primary = '#0B63F6',
        public string $accent = '#B90303',
        public string $surfaceRadius = '14px',
        public string $contentWidth = '1408px',
        public string $density = 'comfortable',
        public string $fontStack = 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI Variable", "Segoe UI", Roboto, Arial, sans-serif',
    ) {
    }
}
