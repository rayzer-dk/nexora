<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Domain;

final readonly class ResolvedProductBlock
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public string $id,
        public string $type,
        public string $region,
        public string $template,
        public int $order,
        public int $mobileOrder,
        public array $settings,
    ) {
    }
}
