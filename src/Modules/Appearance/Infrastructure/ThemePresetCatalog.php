<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Infrastructure;

final readonly class ThemePresetCatalog
{
    public function __construct(private string $configPath)
    {
    }

    /** @return array<string,array{label:string,description_key:string,theme:array<string,string>,form:array<string,string>,home_order:list<string>}> */
    public function all(): array
    {
        $json = @file_get_contents($this->configPath);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.theme_presets_unavailable'));
        }

        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || $data === []) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.theme_presets_invalid'));
        }

        $out = [];
        foreach ($data as $code => $preset) {
            if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $code) || !is_array($preset)) {
                continue;
            }
            $theme = is_array($preset['theme'] ?? null) ? $preset['theme'] : [];
            $out[$code] = [
                'label' => trim((string)($preset['label'] ?? ucfirst($code))),
                'description_key' => trim((string)($preset['description_key'] ?? '')),
                'theme' => array_map(static fn (mixed $value): string => trim((string)$value), $theme),
                'form' => array_map(static fn (mixed $value): string => trim((string)$value), array_intersect_key(is_array($preset['form'] ?? null) ? $preset['form'] : [], array_flip(['theme_concept', 'home_composition', 'header_layout', 'header_nav_style', 'display_card_style', 'display_card_columns', 'display_card_ratio', 'display_category_style', 'display_product_layout', 'display_product_details', 'display_product_sections', 'display_cart_effect']))),
                'home_order' => array_values(array_filter(is_array($preset['home_order'] ?? null) ? $preset['home_order'] : [], static fn (mixed $value): bool => is_string($value) && in_array($value, ['hero', 'category_grid', 'product_grid', 'article_grid', 'recently_viewed'], true))),
            ];
        }

        if ($out === []) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.theme_presets_empty'));
        }

        return $out;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }
}