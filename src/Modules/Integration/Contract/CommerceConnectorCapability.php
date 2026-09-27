<?php

declare(strict_types=1);

namespace Commerce\Modules\Integration\Contract;

enum CommerceConnectorCapability: string
{
    case ProductsRead = 'products.read';
    case ProductsWrite = 'products.write';
    case InventoryRead = 'inventory.read';
    case InventoryWrite = 'inventory.write';
    case PricesRead = 'prices.read';
    case PricesWrite = 'prices.write';
    case OrdersRead = 'orders.read';
    case OrdersWrite = 'orders.write';
    case CustomersRead = 'customers.read';
    case CustomersWrite = 'customers.write';
    case Webhooks = 'webhooks';
}
