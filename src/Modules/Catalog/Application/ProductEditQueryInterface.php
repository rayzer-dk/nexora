<?php

declare(strict_types=1);

namespace Commerce\Modules\Catalog\Application;

interface ProductEditQueryInterface
{
    /** @return array<string,mixed> */
    public function productForEdit(int $storeId, int $marketId, string $locale, string $publicId): array;
}
