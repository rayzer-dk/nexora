<?php

declare(strict_types=1);

namespace Commerce\Modules\Storefront;

use Commerce\Core\Site\SiteCapabilitySettings;
use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;
use Commerce\Modules\Demo\Application\DemoShowcaseQuery;
use Commerce\Modules\Content\Infrastructure\DbalBlogQuery;
use Commerce\Modules\Seo\StructuredData\OrganizationCommerceBuilder;
use Commerce\Modules\Seo\StructuredData\StructuredDataGraphBuilder;
use Commerce\Modules\Seo\StructuredData\WebSiteBuilder;
use Commerce\Modules\Storefront\Infrastructure\DbalStorefrontCatalogQuery;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly DbalStorefrontCatalogQuery $catalog,
        private readonly DbalBlogQuery $blog,
        private readonly SiteCapabilitySettings $capabilities,
        private readonly LayoutRevisionStore $layouts,
        private readonly DemoShowcaseQuery $demoShowcase,
        private readonly WebSiteBuilder $webSite,
        private readonly OrganizationCommerceBuilder $organization,
        private readonly StructuredDataGraphBuilder $graph,
    ) {
    }

    #[Route('/', name: 'storefront_home', methods: ['GET'], priority: 100)]
    public function __invoke(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $settings = $this->capabilities->get($context->storeId);
        $features = is_array($settings['features'] ?? null) ? $settings['features'] : [];

        // Optional homepage blocks are isolated from each other. A broken catalog/blog query
        // removes only that block; it must not make the whole storefront unavailable.
        $categories = [];
        $products = [];
        $articles = [];
        if ((bool) ($features['catalog'] ?? true)) {
            try {
                $categories = $this->catalog->topCategories($context, 12);
            } catch (\Throwable) {
                $categories = [];
            }
            try {
                $products = $this->catalog->products($context, null, 1, 10)['items'];
            } catch (\Throwable) {
                $products = [];
            }
        }
        if ((bool) ($features['blog'] ?? true)) {
            try {
                $articles = $this->blog->latest($context->storeId, $context->locale, 3);
            } catch (\Throwable) {
                $articles = [];
            }
        }

        $demoShowcase = null;
        try {
            $demoShowcase = $this->demoShowcase->homepage($context);
            if (is_array($demoShowcase)) {
                $categories = $demoShowcase['category_tiles'] ?? $categories;
                $articles = $demoShowcase['articles'] ?? $articles;
            }
        } catch (\Throwable) {
            $demoShowcase = null;
        }

        return $this->render('@storefront/home.html.twig', [
            'page_title' => $context->storeName,
            'store_name' => $context->storeName,
            'site_mode' => (string) ($settings['mode'] ?? SiteCapabilitySettings::MODE_SHOP),
            'site_features' => $features,
            'categories' => $categories,
            'products' => $products,
            'articles' => $articles,
            'demo_showcase' => $demoShowcase,
            'home_layout' => $this->layouts->active($context->storeId, 'home'),
            'benefits' => is_array($demoShowcase) ? ($demoShowcase['benefits'] ?? []) : [
                ['title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.shvydka_dostavka'), 'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.zruchnyi_sposib_otrymannia'), 'icon' => 'truck'],
                ['title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.ofitsiina_harantiia'), 'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.prozori_umovy'), 'icon' => 'shield'],
                ['title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.zruchna_oplata'), 'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.dostupni_sposoby_oplaty'), 'icon' => 'card'],
                ['title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.demo.application.demoshowcasequery.pidtrymka'), 'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.kontakty_zavzhdy_poruch'), 'icon' => 'headset'],
                ['title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.povernennia'), 'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.zrozumilyi_protses'), 'icon' => 'box'],
                ['title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.bezpechna_pokupka'), 'text' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.storefront.homecontroller.zakhyshchene_oformlennia'), 'icon' => 'lock'],
            ],
            'seo_head' => [
                'json_ld' => $this->homeStructuredData($request->getSchemeAndHttpHost() . '/', $context->storeName),
                'description' => \Commerce\Core\I18n\CanonicalUiText::get('seo.home.description', ['store' => $context->storeName]),
                'canonical' => $request->getSchemeAndHttpHost() . '/',
                'robots' => 'index,follow,max-image-preview:large',
                'hreflang' => [$context->locale => $request->getSchemeAndHttpHost() . '/', 'x-default' => $request->getSchemeAndHttpHost() . '/'],
            ],
        ]);
    }

    /** Organization + WebSite with a sitelinks SearchAction pointing at the catalog search. */
    private function homeStructuredData(string $homeUrl, string $storeName): array
    {
        $webSite = $this->webSite->build($storeName, $homeUrl);
        $webSite['publisher'] = ['@id' => rtrim($homeUrl, '/') . '#organization'];
        $webSite['potentialAction'] = [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => rtrim($homeUrl, '/') . '/catalog?q={search_term_string}'],
            'query-input' => 'required name=search_term_string',
        ];

        return $this->graph->build(
            $this->organization->build(['name' => $storeName, 'url' => $homeUrl]),
            $webSite,
        );
    }
}
