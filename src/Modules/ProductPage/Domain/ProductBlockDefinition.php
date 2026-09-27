<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Domain;

use InvalidArgumentException;

final readonly class ProductBlockDefinition
{
    /** @param list<string> $allowedRegions */
    public function __construct(
        public string $type,
        public string $template,
        public array $allowedRegions,
        public bool $allowMultiple = false,
    ) {
        if ($this->type === '' || $this->template === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.07a560837bfb'));
        }

        if ($this->allowedRegions === []) {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.f5c079fe8f73'));
        }
    }

    public function supportsRegion(string $region): bool
    {
        return in_array($region, $this->allowedRegions, true);
    }
}
