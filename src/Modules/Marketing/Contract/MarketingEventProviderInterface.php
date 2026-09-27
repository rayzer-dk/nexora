<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketing\Contract;

interface MarketingEventProviderInterface
{
    public function code(): string;
    public function consentScope(): string;
    public function enabled(): bool;
    /** @param array<string,mixed> $event @return array{status:int,body:string} */
    public function send(array $event): array;
}
