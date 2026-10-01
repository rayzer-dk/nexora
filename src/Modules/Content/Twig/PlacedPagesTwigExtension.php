<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Twig;

use Commerce\Modules\Content\Application\InformationPageService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** placed_pages('footer'|'menu'): the merchant's own information pages that are switched on for that place of the storefront. */
final class PlacedPagesTwigExtension extends AbstractExtension
{
    /** @var array<string,list<array{title:string,url:string,group:string}>> */
    private array $memo = [];

    public function __construct(
        private readonly InformationPageService $pages,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('placed_pages', $this->placed(...))];
    }

    /** @return list<array{title:string,url:string,group:string}> */
    public function placed(string $placement): array
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return [];
        }
        try {
            $context = $this->contexts->resolve($request);
            $key = $context->storeId . ':' . $context->locale . ':' . $placement;

            return $this->memo[$key] ??= $this->pages->placed($context->storeId, $context->locale, $placement);
        } catch (\Throwable) {
            return [];
        }
    }
}
