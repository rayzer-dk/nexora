<?php

declare(strict_types=1);

namespace Commerce\Modules\Content\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Content\Infrastructure\DbalBlogQuery;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    private const PER_PAGE = 9;

    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly DbalBlogQuery $blog,
    ) {
    }

    #[Route('/blog', name: 'storefront_blog', methods: ['GET'], priority: 100)]
    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query->get('q', '')), 0, 100, 'UTF-8');
        return $this->listing($request, null, null, $search);
    }

    #[Route('/blog/category/{slug}', name: 'storefront_blog_category', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{0,118}'], priority: 110)]
    public function category(Request $request, string $slug): Response
    {
        return $this->listing($request, $slug, null, '');
    }

    #[Route('/blog/tag/{slug}', name: 'storefront_blog_tag', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{0,118}'], priority: 110)]
    public function tag(Request $request, string $slug): Response
    {
        return $this->listing($request, null, $slug, '');
    }

    #[Route('/blog/feed.xml', name: 'storefront_blog_feed', methods: ['GET'], priority: 120)]
    public function feed(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $base = $request->getSchemeAndHttpHost();
        $items = $this->blog->feed($context->storeId, $context->locale, 30);
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $rss = $xml->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $xml->appendChild($rss);
        $channel = $xml->createElement('channel');
        $rss->appendChild($channel);
        $add = static function (\DOMElement $parent, string $name, string $value) use ($xml): \DOMElement {
            $element = $xml->createElement($name);
            $element->appendChild($xml->createTextNode($value));
            $parent->appendChild($element);
            return $element;
        };
        $add($channel, 'title', $context->storeName . ' — ' . CanonicalUiText::get('php.modules.content.http.blogcontroller.bloh'));
        $add($channel, 'link', $base . '/blog');
        $add($channel, 'description', CanonicalUiText::get('seo.blog.description', ['store' => $context->storeName]));
        $add($channel, 'language', strtolower(explode('-', $context->locale)[0]));
        $self = $xml->createElement('atom:link');
        $self->setAttribute('href', $base . '/blog/feed.xml');
        $self->setAttribute('rel', 'self');
        $self->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($self);
        foreach ($items as $item) {
            $entry = $xml->createElement('item');
            $channel->appendChild($entry);
            $add($entry, 'title', (string) $item['title']);
            $add($entry, 'link', $base . $item['url']);
            $guid = $add($entry, 'guid', $base . $item['url']);
            $guid->setAttribute('isPermaLink', 'true');
            if ($item['iso'] !== '') {
                $add($entry, 'pubDate', gmdate(DATE_RSS, (int) strtotime((string) $item['iso'])));
            }
            $add($entry, 'description', (string) $item['excerpt']);
            if (is_array($item['category'])) {
                $add($entry, 'category', (string) $item['category']['name']);
            }
            $encoded = $xml->createElement('content:encoded');
            $encoded->appendChild($xml->createCDATASection(str_replace(']]>', ']]&gt;', (string) $item['body_html'])));
            $entry->appendChild($encoded);
        }

        return new Response((string) $xml->saveXML(), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=600',
        ]);
    }

    private function listing(Request $request, ?string $categorySlug, ?string $tagSlug, string $search): Response
    {
        $context = $this->contexts->resolve($request);
        $base = $request->getSchemeAndHttpHost();
        $page = max(1, (int) $request->query->get('page', 1));
        $category = null;
        $tagName = null;
        if ($categorySlug !== null) {
            $category = $this->blog->category($context->storeId, $context->locale, $categorySlug);
            if ($category === null) {
                throw $this->createNotFoundException();
            }
        }
        if ($tagSlug !== null) {
            $tagName = $this->blog->tagName($context->storeId, $context->locale, $tagSlug);
            if ($tagName === null) {
                throw $this->createNotFoundException();
            }
        }
        $isPlain = $categorySlug === null && $tagSlug === null && $search === '';
        $featured = $isPlain && $page === 1 ? $this->blog->featured($context->storeId, $context->locale) : null;
        $result = $this->blog->page($context->storeId, $context->locale, $page, self::PER_PAGE, $categorySlug, $tagSlug, $search, $featured !== null);
        if ($page > $result['pages'] && $page > 1) {
            throw $this->createNotFoundException();
        }

        // Subcategories: the chips below the top row are the children of the open category, or its siblings when it has none.
        $categoryTree = $this->blog->categories($context->storeId, $context->locale);
        $bySlug = [];
        foreach ($categoryTree as $node) {
            $bySlug[$node['slug']] = $node;
        }
        $branch = [];
        for ($cursor = $categorySlug; $cursor !== null && isset($bySlug[$cursor]) && count($branch) < 8; $cursor = $bySlug[$cursor]['parent']) {
            array_unshift($branch, $cursor);
        }
        $subCategories = [];
        if ($categorySlug !== null && isset($bySlug[$categorySlug])) {
            $subCategories = array_values(array_filter($categoryTree, static fn (array $node): bool => $node['parent'] === $categorySlug));
            if ($subCategories === [] && $bySlug[$categorySlug]['parent'] !== null) {
                $subCategories = array_values(array_filter($categoryTree, static fn (array $node): bool => $node['parent'] === $bySlug[$categorySlug]['parent']));
            }
        }

        $path = match (true) {
            $categorySlug !== null => '/blog/category/' . $categorySlug,
            $tagSlug !== null => '/blog/tag/' . $tagSlug,
            default => '/blog',
        };
        $canonical = $base . $path . ($search === '' && $page > 1 ? '?page=' . $page : '');
        $heading = $category['name'] ?? ($tagName !== null ? '#' . $tagName : CanonicalUiText::get('php.modules.content.http.blogcontroller.bloh'));
        $description = $category !== null && $category['meta_description'] !== ''
            ? $category['meta_description']
            : ($category !== null && $category['description'] !== '' ? $category['description'] : CanonicalUiText::get('seo.blog.description', ['store' => $context->storeName]));
        $title = $category !== null && $category['meta_title'] !== '' ? $category['meta_title'] : $heading . ($page > 1 ? ' — ' . $page : '') . ' — ' . $context->storeName;

        $listSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Blog',
            'name' => $heading,
            'url' => $base . $path,
            'inLanguage' => $context->locale,
            'blogPost' => array_map(static fn (array $a): array => ['@type' => 'BlogPosting', 'headline' => $a['title'], 'url' => $base . $a['url'], 'datePublished' => $a['iso']], array_slice($result['items'], 0, 10)),
        ];

        return $this->render('@storefront/blog/index.html.twig', [
            'page_title' => $title,
            'store_name' => $context->storeName,
            'heading' => $heading,
            'category' => $category,
            'tag_name' => $tagName,
            'search' => $search,
            'featured' => $featured,
            'articles' => $result['items'],
            'pagination' => ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'], 'path' => $path],
            'categories' => $categoryTree,
            'category_branch' => $branch,
            'sub_categories' => $subCategories,
            'tags' => $this->blog->tags($context->storeId, $context->locale, 15),
            'active_category' => $categorySlug,
            'active_tag' => $tagSlug,
            'seo_head' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $canonical,
                // Tag archives, internal search and deep filters are thin duplicates: keep them out of the index.
                'robots' => ($tagSlug !== null || $search !== '') ? 'noindex,follow' : 'index,follow,max-image-preview:large',
                'hreflang' => ($tagSlug !== null || $search !== '') ? [] : [$context->locale => $canonical, 'x-default' => $canonical],
                'type' => 'website',
                'json_ld' => $listSchema,
            ],
        ]);
    }
}
