<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketplace\Contract;

use Commerce\Modules\Marketplace\Domain\ExternalOrder;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One marketplace. The shop talks to every marketplace through these three questions, so a new one is a new class. */
#[AutoconfigureTag('commerce.marketplace_adapter')]
interface MarketplaceAdapterInterface
{
    public function code(): string;

    public function label(): string;

    /** Switched on in the Connections page and all keys filled. */
    public function isReady(): bool;

    /** @return list<ExternalOrder> orders created at or after $sinceIso (UTC, Y-m-d\TH:i:s), newest data first or last, duplicates are fine */
    public function newOrders(string $sinceIso): array;

    /** @param list<array{external_id:string,sku:string,quantity:int,price:?string}> $items what to show on the marketplace */
    public function pushStock(array $items): void;

    /** @param 'confirmed'|'shipped'|'delivered'|'canceled' $status */
    public function pushStatus(string $externalId, string $status): void;
}
