<?php

declare(strict_types=1);

namespace Commerce\Modules\Promotion\Domain;

final readonly class PromotionResult
{
    /** @param list<array{id:int,name:string,code:?string,discount_minor:int}> $applied */
    public function __construct(
        public int $subtotalMinor,
        public int $discountMinor,
        public int $totalMinor,
        public array $applied,
        public ?string $couponMessage = null,
    ) {}
}
