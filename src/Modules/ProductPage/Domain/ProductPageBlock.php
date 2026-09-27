<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Domain;

use InvalidArgumentException;

final readonly class ProductPageBlock
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public string $id,
        public string $type,
        public string $region,
        public int $order,
        public int $mobileOrder,
        public array $settings = [],
    ) {
        if ($this->id === '' || $this->type === '' || $this->region === '') {
            throw new InvalidArgumentException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.436e52df2a5c'));
        }
    }
}
