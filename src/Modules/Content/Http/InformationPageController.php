<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Http;

use Commerce\Modules\Content\Application\BlogContentProcessor;
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
        private readonly \Commerce\Modules\Storefront\Infrastructure\StorefrontContactSettings $contact,
        private readonly \Commerce\Modules\Seo\StructuredData\OrganizationCommerceBuilder $organization,
        private readonly \Commerce\Modules\Seo\StructuredData\StructuredDataGraphBuilder $graph,
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

        $jsonLd = null;
        if ($key === 'contacts') {
            try {
                $home = $request->getSchemeAndHttpHost() . '/';
                $jsonLd = $this->graph->build(['@type' => 'ContactPage', 'name' => (string) ($page['title'] ?: $definition->title), 'url' => $canonical], $this->organization->build(['name' => $context->storeName, 'url' => $home]) + $this->contact->organizationData($context->storeId));
            } catch (\Throwable) {
                $jsonLd = null;
            }
        }
        $anchored = BlogContentProcessor::withAnchors((string) ($page['body_html'] ?? ''));
        $page['body_html'] = $anchored['html'];
        $related = [];
        try {
            $titles = $this->pages->publishedTitles($context);
            foreach ($this->definitions->all() as $other) {
                if (!isset($titles[$other->key]) || $other->key === $key) {
                    continue;
                }
                $related[] = ['title' => $titles[$other->key], 'url' => '/' . $this->routes->route($other->routeKey, $context->locale)->path];
            }
        } catch (\Throwable) {
            $related = [];
        }

        return $this->render('@storefront/content/page.html.twig', [
            'toc' => count($anchored['toc']) >= 3 ? $anchored['toc'] : [],
            'related_pages' => $related,
            'page_title' => (string) ($page['title'] ?: $definition->title),
            'store_name' => $context->storeName,
            'page' => $page,
            'published' => $published,
            'seo_head' => [
                'title' => (string) ($page['meta_title'] ?? ''),
                'description' => (string) ($page['meta_description'] ?? '') !== '' ? (string) $page['meta_description'] : (string) ($page['excerpt'] ?? ''),
                'canonical' => $canonical,
                'json_ld' => $jsonLd,
                'robots' => $published && $definition->indexable ? 'index,follow,max-image-preview:large' : 'noindex,follow',
                'hreflang' => [$context->locale => $canonical, 'x-default' => $canonical],
            ],
        ], new Response('', $published ? 200 : 503));
    }
}
