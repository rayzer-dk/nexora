<?php

declare(strict_types=1);

namespace Commerce\Modules\Migration\Domain;

enum MigrationEntityType: string
{
    case Locale = 'locale';
    case Currency = 'currency';
    case Brand = 'brand';
    case Category = 'category';
    case Attribute = 'attribute';
    case Product = 'product';
    case Variant = 'variant';
    case Media = 'media';
    case Price = 'price';
    case Inventory = 'inventory';
    case Customer = 'customer';
    case Order = 'order';
}
