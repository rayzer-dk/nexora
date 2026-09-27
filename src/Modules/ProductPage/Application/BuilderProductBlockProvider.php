<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Application;

use Commerce\Modules\ProductPage\Contract\ProductBlockProviderInterface;
use Commerce\Modules\ProductPage\Domain\ProductBlockDefinition;

final class BuilderProductBlockProvider implements ProductBlockProviderInterface
{
    public function definitions(): array
    {
        return [
            new ProductBlockDefinition('custom_rich_text', '@storefront/product/blocks/custom_rich_text.html.twig', ['hero_summary','below_primary','below_secondary'], true),
            new ProductBlockDefinition('custom_image', '@storefront/product/blocks/custom_image.html.twig', ['hero_media','hero_summary','below_primary','below_secondary'], true),
            new ProductBlockDefinition('info_card', '@storefront/product/blocks/info_card.html.twig', ['hero_summary','below_primary','below_secondary'], true),
        ];
    }
}
