<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Contract;

interface CatalogCacheVersionInterface
{
    public function bump(): void;
}
