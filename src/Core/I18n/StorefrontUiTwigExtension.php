<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class StorefrontUiTwigExtension extends AbstractExtension
{
    /** @var array<string,string> */
    private const ICONS = [
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'shopping-cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57L22 7H5.12"/>',
        'menu' => '<path d="M4 12h16M4 6h16M4 18h16"/>',
        'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78Z"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-left' => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'arrow-left-right' => '<path d="M8 3 4 7l4 4M4 7h16M16 21l4-4-4-4M20 17H4"/>',
        'arrow-up-down' => '<path d="m21 16-4 4-4-4M17 20V4M3 8l4-4 4 4M7 4v16"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'plus' => '<path d="M5 12h14M12 5v14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'check' => '<path d="m20 6-11 11-5-5"/>',
        'check-circle' => '<path d="M22 11.1V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        'eye' => '<path d="M2.06 12.35a1 1 0 0 1 0-.7C3.59 7.79 7.35 5 12 5c4.65 0 8.41 2.79 9.94 6.65a1 1 0 0 1 0 .7C20.41 16.21 16.65 19 12 19c-4.65 0-8.41-2.79-9.94-6.65Z"/><circle cx="12" cy="12" r="3"/>',
        'pencil' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'trash-2' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v5M14 11v5"/>',
        'home' => '<path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/>',
        'package' => '<path d="m7.5 4.27 9 5.15M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
        'box' => '<path d="m7.5 4.27 9 5.15M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
        'truck' => '<path d="M10 17h4V5H2v12h3M14 8h4l4 4v5h-2M14 17h1"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3Z"/><path d="m9 12 2 2 4-4"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V5l8-3 8 3Z"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'circle-alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'sliders-horizontal' => '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3"/><path d="M14 2v4M8 10v4M16 18v4"/>',
        'grid-2x2' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'star' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01Z"/>',
        'star-filled' => '<path fill="currentColor" stroke="currentColor" d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01Z"/>',
        'star-empty' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01Z"/>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'credit-card' => '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/>',
        'card' => '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/>',
        'circle-user-round' => '<path d="M18 20a6 6 0 0 0-12 0"/><circle cx="12" cy="10" r="4"/><circle cx="12" cy="12" r="10"/>',
        'rotate-ccw' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'refresh-cw' => '<path d="M21 12a9 9 0 0 0-15.5-6.2L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15.5 6.2L21 16"/><path d="M16 16h5v5"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.09a2 2 0 0 1-1-1.74v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z"/><circle cx="12" cy="12" r="3"/>',
        'play' => '<path d="m6 3 14 9-14 9Z"/>',
        'pause' => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
        'square' => '<rect width="14" height="14" x="5" y="5" rx="1"/>',
        'external-link' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'grip-vertical' => '<circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/>',
        'badge-percent' => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'undo-2' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        'redo-2' => '<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'list-ordered' => '<path d="M10 6h11M10 12h11M10 18h11"/><path d="M4 6h1V3M4 3h2M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
        'bold' => '<path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6zM6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/>',
        'italic' => '<path d="M19 4h-9M14 20H5M15 4 9 20"/>',
        'underline' => '<path d="M6 4v6a6 6 0 0 0 12 0V4M4 20h16"/>',
        'heading-2' => '<path d="M4 12h8M4 5v14M12 5v14M17 10a3 3 0 1 1 6 0c0 3-6 3-6 7h6"/>',
        'link-2' => '<path d="M10 13a5 5 0 0 0 7.07.07l2-2a5 5 0 0 0-7.07-7.07l-1.15 1.15"/><path d="M14 11a5 5 0 0 0-7.07-.07l-2 2A5 5 0 0 0 12 20l1.15-1.15"/>',
        'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'pin' => '<path d="M12 17v5M5 17h14M6 3h12l-2 7 3 3v4H5v-4l3-3z"/>',
        'headset' => '<path d="M4 13a8 8 0 0 1 16 0"/><path d="M18 19c0 1.7-1.3 3-3 3h-3"/><path d="M4 13v3a2 2 0 0 0 2 2h1v-7H6a2 2 0 0 0-2 2ZM20 13v3a2 2 0 0 1-2 2h-1v-7h1a2 2 0 0 1 2 2Z"/>',
    ];

    public function __construct(
        private readonly StorefrontUiTranslator $translator,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ui_text', $this->text(...), ['needs_context' => true]),
            new TwigFunction('ui_catalog', $this->catalog(...), ['needs_context' => true]),
            new TwigFunction('ui_catalog_json', $this->catalogJson(...), ['needs_context' => true, 'is_safe' => ['html']]),
            new TwigFunction('ui_icon', $this->icon(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('forum_ugc', $this->forumUgc(...), ['is_safe' => ['html']])];
    }

    public function icon(string $name, int $size = 20, string $class = ''): string
    {
        $body = self::ICONS[$name] ?? self::ICONS['info'];
        $size = max(12, min(64, $size));
        $safeClass = preg_replace('/[^a-zA-Z0-9_:\- ]/', '', $class) ?: '';
        $classAttr = trim('ui-icon ' . $safeClass);

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            htmlspecialchars($classAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $size,
            $size,
            $body,
        );
    }

    /** @param array<string,mixed> $context @param list<string> $prefixes */
    public function catalogJson(array $context, array $prefixes = []): string
    {
        $catalog = $this->catalog($context);
        if ($prefixes !== []) {
            $catalog = array_filter(
                $catalog,
                static function (string $key) use ($prefixes): bool {
                    foreach ($prefixes as $prefix) {
                        if (str_starts_with($key, (string) $prefix)) {
                            return true;
                        }
                    }
                    return false;
                },
                ARRAY_FILTER_USE_KEY,
            );
        }

        try {
            return json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        } catch (\JsonException) {
            return '{}';
        }
    }

    public function forumUgc(string $text): string
    {
        $parts = preg_split('~(https?://[^\s<>]+)~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $html = '';
        foreach ($parts as $part) {
            if (preg_match('~^https?://~iu', $part) === 1 && filter_var($part, FILTER_VALIDATE_URL) !== false) {
                $safe = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<a href="' . $safe . '" rel="ugc nofollow noopener noreferrer" target="_blank">' . $safe . '</a>';
                continue;
            }
            $html .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return nl2br($html, false);
    }

    /** @param array<string,mixed> $context @return array<string,string> */
    public function catalog(array $context): array
    {
        $locale = trim((string)($context['locale'] ?? ''));
        if ($locale === '') {
            $locale = $this->requests->getCurrentRequest()?->getLocale() ?? 'uk-UA';
        }

        return $this->translator->catalogFor($locale);
    }

    /** @param array<string,mixed> $context */
    public function text(array $context, string $key, array $replace = []): string
    {
        $locale = trim((string)($context['locale'] ?? ''));
        if ($locale === '') {
            $locale = $this->requests->getCurrentRequest()?->getLocale() ?? 'uk-UA';
        }

        return $this->translator->translate($key, $locale, $replace);
    }
}
