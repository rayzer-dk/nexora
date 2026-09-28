<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Http;

use Commerce\Modules\Content\Infrastructure\DbalBlogQuery;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly DbalBlogQuery $blog,
    ) {
    }

    #[Route('/blog', name: 'storefront_blog', methods: ['GET'], priority: 100)]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $canonical = $request->getSchemeAndHttpHost() . '/blog';

        return $this->render('@storefront/blog/index.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.content.http.blogcontroller.bloh'),
            'store_name' => $context->storeName,
            'articles' => $this->blog->latest($context->storeId, $context->locale, 24),
            'seo_head' => [
                'description' => \Commerce\Core\I18n\CanonicalUiText::get('seo.blog.description', ['store' => $context->storeName]),
                'canonical' => $canonical,
                'robots' => 'index,follow,max-image-preview:large',
                'hreflang' => [$context->locale => $canonical, 'x-default' => $canonical],
            ],
        ]);
    }
}
