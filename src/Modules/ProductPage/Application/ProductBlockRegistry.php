<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductPage\Application;

use Commerce\Modules\ProductPage\Contract\ProductBlockProviderInterface;
use Commerce\Modules\ProductPage\Domain\ProductBlockDefinition;
use DomainException;

final class ProductBlockRegistry
{
    /** @var array<string, ProductBlockDefinition> */
    private array $definitions = [];

    /** @param iterable<ProductBlockProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(ProductBlockProviderInterface $provider): void
    {
        foreach ($provider->definitions() as $definition) {
            if (isset($this->definitions[$definition->type])) {
                throw new DomainException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.db17fcd77292'), $definition->type));
            }
            $this->definitions[$definition->type] = $definition;
        }
    }

    public function get(string $type): ProductBlockDefinition
    {
        return $this->definitions[$type]
            ?? throw new DomainException(sprintf(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.extra.639fd5e7c558'), $type));
    }

    /** @return array<string, ProductBlockDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
