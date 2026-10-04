<?php

declare(strict_types=1);

namespace Commerce\Modules\Feeds\Contract;

/**
 * A product feed format contributed by a module (a marketplace or price aggregator). It appears next to the built-in
 * formats on the feeds page, in `feeds:generate` and at /feeds/{store}/{code}.
 */
interface FeedFormatProviderInterface
{
    /** Stable code of 2-30 characters [a-z0-9_]; it must not collide with a built-in format. */
    public function code(): string;

    public function label(): string;

    /**
     * @param list<array<string,mixed>> $products canonical export rows (sku, name, price, availability, images, categories, ...)
     * @return array{content:string,content_type:string,extension:string}
     */
    public function render(array $products, int $storeId, string $locale, string $currency): array;
}
