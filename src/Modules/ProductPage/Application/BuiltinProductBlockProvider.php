<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Application;

use Commerce\Modules\ProductPage\Contract\ProductBlockProviderInterface;
use Commerce\Modules\ProductPage\Domain\ProductBlockDefinition;

final class BuiltinProductBlockProvider implements ProductBlockProviderInterface
{
    public function definitions(): array
    {
        return [
            new ProductBlockDefinition('media_gallery', '@storefront/product/blocks/media_gallery.html.twig', ['hero_media']),
            new ProductBlockDefinition('title_meta', '@storefront/product/blocks/title_meta.html.twig', ['hero_summary']),
            new ProductBlockDefinition('rating_summary', '@storefront/product/blocks/rating_summary.html.twig', ['hero_summary']),
            new ProductBlockDefinition('price', '@storefront/product/blocks/price.html.twig', ['hero_summary']),
            new ProductBlockDefinition('availability', '@storefront/product/blocks/availability.html.twig', ['hero_summary']),
            new ProductBlockDefinition('key_features', '@storefront/product/blocks/key_features.html.twig', ['hero_summary']),
            new ProductBlockDefinition('variants', '@storefront/product/blocks/variants.html.twig', ['hero_summary']),
            new ProductBlockDefinition('buy_actions', '@storefront/product/blocks/buy_actions.html.twig', ['hero_summary']),
            new ProductBlockDefinition('fulfillment_preview', '@storefront/product/blocks/fulfillment_preview.html.twig', ['hero_summary']),
            new ProductBlockDefinition('payment_trust', '@storefront/product/blocks/payment_trust.html.twig', ['hero_summary']),
            new ProductBlockDefinition('description', '@storefront/product/blocks/description.html.twig', ['below_primary', 'below_secondary']),
            new ProductBlockDefinition('attributes', '@storefront/product/blocks/attributes.html.twig', ['below_primary', 'below_secondary']),
            new ProductBlockDefinition('documents', '@storefront/product/blocks/documents.html.twig', ['below_primary', 'below_secondary']),
            new ProductBlockDefinition('compliance', '@storefront/product/blocks/compliance.html.twig', ['below_primary', 'below_secondary']),
            new ProductBlockDefinition('consumer_rights', '@storefront/product/blocks/consumer_rights.html.twig', ['below_primary', 'below_secondary']),
            new ProductBlockDefinition('reviews', '@storefront/product/blocks/reviews.html.twig', ['below_primary', 'below_secondary']),
            new ProductBlockDefinition('qa', '@storefront/product/blocks/qa.html.twig', ['below_primary', 'below_secondary']),
            new ProductBlockDefinition('related_products', '@storefront/product/blocks/related_products.html.twig', ['below_primary', 'below_secondary']),
            new ProductBlockDefinition('sticky_buy_bar', '@storefront/product/blocks/sticky_buy_bar.html.twig', ['mobile_sticky']),
        ];
    }
}
