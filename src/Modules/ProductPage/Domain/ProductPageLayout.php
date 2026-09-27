<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Domain;

final readonly class ProductPageLayout
{
    /** @param list<ProductPageBlock> $blocks */
    public function __construct(
        public int $schemaVersion,
        public string $code,
        public string $name,
        public array $blocks,
    ) {
    }
}
