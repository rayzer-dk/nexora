<?php

declare(strict_types=1);

namespace Commerce\Modules\Localization\Domain;

use InvalidArgumentException;

final readonly class LocaleDefinition
{
    public function __construct(
        public string $code,
        public string $name,
        public string $nativeName,
        public string $direction = 'ltr',
    ) {
        if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $code) !== 1) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.40c2bb053d60'));
        }
        if (!in_array($direction, ['ltr', 'rtl'], true)) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.fb90621848bd'));
        }
    }

    public function languageCode(): string
    {
        return explode('-', $this->code, 2)[0];
    }

    public function regionCode(): ?string
    {
        $parts = explode('-', $this->code, 2);
        return $parts[1] ?? null;
    }
}
