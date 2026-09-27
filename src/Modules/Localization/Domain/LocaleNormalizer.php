<?php

declare(strict_types=1);

namespace Commerce\Modules\Localization\Domain;

use InvalidArgumentException;

final class LocaleNormalizer
{
    public function normalize(string $locale): string
    {
        $locale = str_replace('_', '-', trim($locale));
        $parts = array_values(array_filter(explode('-', $locale), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.7d20ce6d242b'));
        }

        $language = strtolower($parts[0]);
        $region = isset($parts[1]) ? strtoupper($parts[1]) : null;
        $normalized = $region !== null ? $language . '-' . $region : $language;

        if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.3e5fba9b888b'), $locale));
        }

        return $normalized;
    }
}
