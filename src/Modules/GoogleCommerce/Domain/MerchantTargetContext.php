<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Domain;

use InvalidArgumentException;

final readonly class MerchantTargetContext
{
    public function __construct(
        public string $locale,
        public string $currency,
        public string $country,
        public string $feedLabel,
    ) {
        if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.0755082bc34f'));
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.9934f5181b04'));
        }
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.89af09b63729'));
        }
        if ($feedLabel === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.1c5cbb8581c8'));
        }
    }

    public function contentLanguage(): string
    {
        return explode('-', $this->locale, 2)[0];
    }
}
