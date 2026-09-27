<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Application;

final class EntitySlugSourceSelector
{
    /**
     * Prefer an English localized label for stable English-word entity URLs.
     * Fall back to the current localized label; SlugGenerator will produce safe ASCII transliteration.
     *
     * @param array<string,string> $localizedLabels BCP-47 locale => label
     * @return array{label:string,locale:string,source:string}
     */
    public function select(array $localizedLabels, string $currentLocale, string $currentLabel): array
    {
        foreach (['en-GB', 'en-US', 'en'] as $locale) {
            $label = trim((string) ($localizedLabels[$locale] ?? ''));
            if ($label !== '') {
                return ['label' => $label, 'locale' => $locale, 'source' => 'english_translation'];
            }
        }

        return [
            'label' => trim($currentLabel),
            'locale' => $currentLocale,
            'source' => 'localized_ascii_fallback',
        ];
    }
}
