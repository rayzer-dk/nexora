<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Application;

use Commerce\Core\Extension\ExtensionContributionRegistry;
use Commerce\Modules\ProductPage\Contract\ProductBlockProviderInterface;
use Commerce\Modules\ProductPage\Domain\ProductBlockDefinition;

final readonly class ExtensionProductBlockProvider implements ProductBlockProviderInterface
{
    public function __construct(private ExtensionContributionRegistry $extensions)
    {
    }

    public function definitions(): array
    {
        $out = [];
        foreach ($this->extensions->blocksFor('product') as $block) {
            $id = (string) ($block['id'] ?? '');
            $regions = array_values(array_filter((array) ($block['regions'] ?? []), 'is_string'));
            if ($id === '' || $regions === []) {
                continue;
            }
            $out[] = new ProductBlockDefinition(
                $id,
                '@storefront/product/blocks/extension_component.html.twig',
                $regions,
                (bool) ($block['allow_multiple'] ?? true),
            );
        }
        return $out;
    }
}
