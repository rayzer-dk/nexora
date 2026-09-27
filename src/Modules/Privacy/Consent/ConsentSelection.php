<?php

declare(strict_types=1);

namespace Commerce\Modules\Privacy\Consent;

use InvalidArgumentException;

final readonly class ConsentSelection
{
    public function __construct(
        public bool $necessary = true,
        public bool $preferences = false,
        public bool $analytics = false,
        public bool $marketing = false,
    ) {
        if (!$necessary) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.5a7b8da9eb92'));
        }
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return [
            ConsentCategory::Necessary->value => true,
            ConsentCategory::Preferences->value => $this->preferences,
            ConsentCategory::Analytics->value => $this->analytics,
            ConsentCategory::Marketing->value => $this->marketing,
        ];
    }

    public function allows(ConsentCategory $category): bool
    {
        return match ($category) {
            ConsentCategory::Necessary => true,
            ConsentCategory::Preferences => $this->preferences,
            ConsentCategory::Analytics => $this->analytics,
            ConsentCategory::Marketing => $this->marketing,
        };
    }
}
