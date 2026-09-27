<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Application;

use Commerce\Modules\ProductPage\Domain\ProductPageLayout;
use Commerce\Modules\ProductPage\Domain\ResolvedProductBlock;

final readonly class ProductPageComposer
{
    public function __construct(private ProductBlockRegistry $registry)
    {
    }

    /** @return array<string, list<ResolvedProductBlock>> */
    public function compose(ProductPageLayout $layout): array
    {
        $regions = [];

        foreach ($layout->blocks as $block) {
            $definition = $this->registry->get($block->type);
            $regions[$block->region][] = new ResolvedProductBlock(
                id: $block->id,
                type: $block->type,
                region: $block->region,
                template: $definition->template,
                order: $block->order,
                mobileOrder: $block->mobileOrder,
                settings: $block->settings,
            );
        }

        foreach ($regions as &$blocks) {
            usort($blocks, static fn (ResolvedProductBlock $a, ResolvedProductBlock $b): int => $a->order <=> $b->order);
        }
        unset($blocks);

        return $regions;
    }
}
