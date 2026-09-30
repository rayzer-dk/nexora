<?php

declare(strict_types=1);

namespace Commerce\Modules\ProductInfo\Twig;

use Commerce\Modules\Content\Application\ArticleProductLinkService;
use Commerce\Modules\ProductInfo\Application\ProductInfoService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ProductInfoTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProductInfoService $info,
        private readonly ArticleProductLinkService $links,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
    )
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('product_info_blocks', $this->blocks(...)),
            new TwigFunction('product_articles', $this->articles(...)),
            new TwigFunction('article_products', $this->products(...)),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function blocks(mixed $productId): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !is_numeric($productId) || (int) $productId <= 0) {
            return [];
        }

        return $this->info->forStorefront((int) $productId, str_replace('_', '-', $request->getLocale()));
    }

    /** @return list<array{title:string,path:string}> */
    public function articles(mixed $productId): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !is_numeric($productId) || (int) $productId <= 0) {
            return [];
        }
        $context = $this->contexts->resolve($request);

        return $this->links->articlesForProduct($context->storeId, $context->locale, (int) $productId);
    }

    /** @return list<array{name:string,path:string}> */
    public function products(mixed $contentId): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !is_numeric($contentId) || (int) $contentId <= 0) {
            return [];
        }
        $context = $this->contexts->resolve($request);

        return $this->links->productsForArticle($context->storeId, $context->locale, (int) $contentId);
    }
}
