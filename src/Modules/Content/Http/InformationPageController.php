<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Http;

use Commerce\Modules\Content\Infrastructure\DbalInformationPageQuery;
use Commerce\Modules\Content\System\InformationPageCatalog;
use Commerce\Modules\Seo\System\SystemPageRouteCatalog;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InformationPageController extends AbstractController
{
    private const PATH_TO_KEY = [
        'about-us' => 'about',
        'contact' => 'contacts',
        'shipping' => 'delivery',
        'payment' => 'payment',
        'returns' => 'returns',
        'warranty' => 'warranty',
        'faq' => 'faq',
        'privacy-policy' => 'privacy',
        'cookie-policy' => 'cookies',
        'terms-and-conditions' => 'terms',
    ];

    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly DbalInformationPageQuery $pages,
        private readonly InformationPageCatalog $definitions,
        private readonly SystemPageRouteCatalog $routes,
    ) {
    }

    #[Route('/{path}', name: 'storefront_information_page', methods: ['GET'], requirements: ['path' => 'about-us|contact|shipping|payment|returns|warranty|faq|privacy-policy|cookie-policy|terms-and-conditions'], priority: 900)]
    public function show(Request $request, string $path): Response
    {
        $key = self::PATH_TO_KEY[$path] ?? null;
        if ($key === null) {
            throw $this->createNotFoundException();
        }
        $context = $this->contexts->resolve($request);
        $definition = $this->definitions->get($key);
        $route = $this->routes->route($definition->routeKey, $context->locale);
        $page = $this->pages->bySystemKey($context, $key);
        if ($page === null) {
            // Not translated into the chosen language yet: show the default-language page, never a 404.
            $defaultLocale = $this->contexts->defaultLocale($context->storeId);
            if ($defaultLocale !== null && $defaultLocale !== $context->locale) {
                $fallbackContext = new \Commerce\Modules\Storefront\Domain\StorefrontContext($context->storeId, $context->marketId, $defaultLocale, $context->currency, $context->countryCode, $context->storeName);
                $page = $this->pages->bySystemKey($fallbackContext, $key);
                if ($page !== null) {
                    $request->attributes->set('_content_locale_fallback', $defaultLocale);
                    $route = $this->routes->route($definition->routeKey, $defaultLocale);
                }
            }
        }
        if ($page === null) {
            throw $this->createNotFoundException();
        }

        $published = $page['status'] === 'published' && trim((string) ($page['body_html'] ?? '')) !== '';
        $canonical = $request->getSchemeAndHttpHost() . '/' . $route->path;

        return $this->render('@storefront/content/page.html.twig', [
            'page_title' => (string) ($page['title'] ?: $definition->title),
            'store_name' => $context->storeName,
            'page' => $page,
            'published' => $published,
            'seo_head' => [
                'title' => (string) ($page['meta_title'] ?? ''),
                'description' => (string) ($page['meta_description'] ?? '') !== '' ? (string) $page['meta_description'] : (string) ($page['excerpt'] ?? ''),
                'canonical' => $canonical,
                'robots' => $published && $definition->indexable ? 'index,follow,max-image-preview:large' : 'noindex,follow',
                'hreflang' => [$context->locale => $canonical, 'x-default' => $canonical],
            ],
        ], new Response('', $published ? 200 : 503));
    }
}
