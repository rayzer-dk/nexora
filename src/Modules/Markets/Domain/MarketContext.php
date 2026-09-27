<?php

declare(strict_types=1);

namespace Commerce\Modules\Markets\Domain;

use InvalidArgumentException;

final readonly class MarketContext
{
    /** @param list<string> $countryCodes */
    public function __construct(
        public string $code,
        public string $locale,
        public string $currency,
        public array $countryCodes,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/i', $code) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b90a3e1a8726'));
        }
        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.225822c870d6'));
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.b4399cb2870b'));
        }
        foreach ($countryCodes as $countryCode) {
            if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
                throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5b414f93c3d6'));
            }
        }
    }
}
