<?php

declare(strict_types=1);

namespace Commerce\Modules\CustomField\Twig;

use Commerce\Modules\CustomField\Application\CustomFieldService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CustomFieldTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly CustomFieldService $fields,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('product_custom_fields', $this->forProduct(...))];
    }

    /** @return list<array{label:string,value:string,type:string}> */
    public function forProduct(mixed $productId): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !is_numeric($productId) || (int) $productId <= 0) {
            return [];
        }

        return $this->fields->publicFields($this->contexts->resolve($request)->storeId, (int) $productId);
    }
}
