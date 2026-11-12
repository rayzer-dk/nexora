<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront\Contract;

interface PickupPointResolverInterface
{
    /** @return array{id:int,name:string,city:string,address:string,working_hours:string,phone:string,sort_order:int,enabled:bool}|null */
    public function resolveForCheckout(int $storeId, string $storeName, int $id): ?array;
}
