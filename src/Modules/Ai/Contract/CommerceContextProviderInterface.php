<?php

declare(strict_types=1);

namespace Commerce\Modules\Ai\Contract;

interface CommerceContextProviderInterface
{
    public function type(): string;

    /** @return array<string, mixed> */
    public function context(string $entityId, string $locale): array;
}
