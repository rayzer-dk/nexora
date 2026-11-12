<?php

declare(strict_types=1);

namespace Commerce\Modules\GoogleCommerce\Application;

interface MerchantApiInterface
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function upsertProduct(array $input): array;

    public function deleteProduct(string $contentLanguage, string $feedLabel, string $offerId): void;

    /** @return array<string,mixed> */
    public function getProcessedProduct(string $name): array;
}
