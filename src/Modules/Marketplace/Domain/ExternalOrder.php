<?php

declare(strict_types=1);

namespace Commerce\Modules\Marketplace\Domain;

/** An order as a marketplace reports it, before it becomes an order of the shop. */
final readonly class ExternalOrder
{
    /** @param list<array{sku:string,external_id:string,quantity:string,price:string}> $lines */
    public function __construct(
        public string $channel,
        public string $externalId,
        public string $name,
        public string $phone,
        public string $email,
        public string $comment,
        public string $delivery,
        public array $lines,
    ) {
    }
}
