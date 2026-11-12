<?php

declare(strict_types=1);

namespace Commerce\Modules\Appearance\Contract;

interface StorefrontPresentationReaderInterface
{
    /** @return array<string,mixed> */
    public function getRaw(int $storeId): array;
}
