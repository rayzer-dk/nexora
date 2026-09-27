<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Taxonomy;

final readonly class GoogleProductCategoryResolution
{
    public function __construct(
        public ?string $value,
        public string $source,
    ) {
    }

    public function letsGoogleClassifyAutomatically(): bool
    {
        return $this->value === null;
    }
}
