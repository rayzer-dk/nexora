<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Content\Application\BlogService;
use Commerce\Modules\Media\Application\MediaImageService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin blog: article list, editor (per content language), categories. */
final class BlogAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly BlogService $blog,
        private readonly MediaImageService $media,
        private readonly Connection $db,
        private readonly \Commerce\Modules\Localization\Application\ContentPolicyService $contentPolicy,
        private readonly \Commerce\Modules\Admin\Application\ContentLanguageTabs $languageTabs,
    ) {
    }

    #[Route('/admin/content/blog', name: 'admin_content_blog', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $filters = [
            'status' => (string) $request->query->get('status', ''),
            'q' => (string) $request->query->get('q', ''),
            'category' => (int) $request->query->get('category', 0),
        ];
        $list = $this->blog->list($ctx->storeId, $ctx->locale, $filters, max(1, (int) $request->query->get('page', 1)));
        return $this->render('@storefront/admin/content/blog.html.twig', [
            'list' => $list,
            'filters' => $filters,
            'categories' => $this->blog->categories($ctx->storeId, $ctx->locale),
            'locale' => $ctx->locale,
        ]);
    }

    #[Route('/admin/content/blog/new', name: 'admin_content_blog_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        return $this->form($ctx->storeId, $ctx->locale, null, $this->blank());
    }

    #[Route('/admin/content/blog/{id}/edit', name: 'admin_content_blog_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $ctx = $this->contexts->resolve($request);
        $article = $this->blog->find($ctx->storeId, $id, $ctx->locale);
        if ($article === null) {
            throw $this->createNotFoundException();
        }
        if ($request->query->get('prefill') === 'default' && !$article['has_translation']) {
            // "Copy from default language": the default-language text is shown as a starting point and stored only on Save.
            foreach ($this->languageTabs->tabs($ctx->storeId, $ctx->locale) as $tab) {
                $source = $tab['is_default'] ? $this->blog->find($ctx->storeId, $id, $tab['code']) : null;
                if ($source !== null && $source['has_translation']) {
                    foreach (['title', 'excerpt', 'body_html', 'meta_title', 'meta_description'] as $field) {
                        $article[$field] = $source[$field];
                    }
                    $article['prefilled'] = 'default';
                }
            }
        }
        return $this->form($ctx->storeId, $ctx->locale, $id, $article);
    }

    #[Route('/admin/content/blog/save', name: 'admin_content_blog_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_content_blog', (string) $request->request->get('_token'))) {
            $this->addFlash('error', CanonicalUiText::get('php.modules.admin.http.contentadminpagecontroller.sesiiu_formy_vtracheno_povtorit_diiu'));
            return $this->redirectToRoute('admin_content_blog');
        }
        $ctx = $this->contexts->resolve($request);
        $id = ((int) $request->request->get('id', 0)) ?: null;
        $in = $request->request->all();
        $in['featured'] = $request->request->getBoolean('featured');
        $in['noindex'] = $request->request->getBoolean('noindex');
        $previous = $id !== null ? $this->blog->find($ctx->storeId, $id, $ctx->locale) : null;
        $in['cover_url'] = $previous['cover_url'] ?? '';
        if ($request->request->getBoolean('cover_remove')) {
            $in['cover_url'] = '';
        }
        try {
            $cover = $request->files->get('cover');
            if ($cover instanceof UploadedFile) {
                $in['cover_url'] = $this->media->upload($cover, $ctx->storeId)->url;
            }
            $missingLocales = [];
            if ((string) ($in['status'] ?? '') === 'published' && ($policy = $this->contentPolicy->mode($ctx->storeId)) !== 'off') {
                $translated = $id !== null ? array_map('strval', $this->db->fetchFirstColumn('SELECT locale FROM mc_content_translation WHERE content_id = ?', [$id])) : [];
                $translated[] = $ctx->locale;
                $enabled = array_map('strval', $this->db->fetchFirstColumn('SELECT locale_code FROM mc_store_locale WHERE store_id=? AND enabled=1', [$ctx->storeId]));
                $missingLocales = array_values(array_diff($enabled, $translated));
                if ($missingLocales !== [] && $policy === 'block') {
                    throw new \InvalidArgumentException(CanonicalUiText::get('admin.translations.publish_blocked', ['locales' => implode(', ', $missingLocales)]));
                }
            }
            $admin = $this->getUser();
            $savedId = $this->blog->save($ctx->storeId, $ctx->locale, $id, $in, $admin !== null ? $admin->getUserIdentifier() : 'admin');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            $in['id'] = $id;
            $in['tags'] = (string) ($in['tags'] ?? '');
            return $this->form($ctx->storeId, $ctx->locale, $id, $this->merge($previous ?? $this->blank(), $in));
        }
        $this->addFlash('success', CanonicalUiText::get('admin.blog.saved'));
        if ($missingLocales !== []) {
            $this->addFlash('warning', CanonicalUiText::get('admin.translations.publish_warning', ['locales' => implode(', ', $missingLocales)]));
        }
        return $this->redirectToRoute('admin_content_blog_edit', ['id' => $savedId]);
    }

    #[Route('/admin/content/blog/{id}/delete', name: 'admin_content_blog_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): RedirectResponse
    {
        if ($this->isCsrfTokenValid('admin_content_blog', (string) $request->request->get('_token'))) {
            $ctx = $this->contexts->resolve($request);
            $this->blog->delete($ctx->storeId, $id);
            $this->addFlash('success', CanonicalUiText::get('admin.blog.deleted'));
        }
        return $this->redirectToRoute('admin_content_blog');
    }

    #[Route('/admin/content/blog/categories', name: 'admin_content_blog_categories', methods: ['GET'])]
    public function categories(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $editing = (int) $request->query->get('edit', 0);
        return $this->render('@storefront/admin/content/blog_categories.html.twig', [
            'categories' => $this->blog->categories($ctx->storeId, $ctx->locale),
            'editing' => $editing > 0 ? $this->blog->category($ctx->storeId, $editing, $ctx->locale) : null,
            'locale' => $ctx->locale,
        ]);
    }

    #[Route('/admin/content/blog/categories/save', name: 'admin_content_blog_category_save', methods: ['POST'])]
    public function saveCategory(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_content_blog_categories', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_content_blog_categories');
        }
        $ctx = $this->contexts->resolve($request);
        $id = ((int) $request->request->get('id', 0)) ?: null;
        try {
            $this->blog->saveCategory($ctx->storeId, $ctx->locale, $id, $request->request->all());
            $this->addFlash('success', CanonicalUiText::get('admin.blog.category_saved'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('admin_content_blog_categories');
    }

    #[Route('/admin/content/blog/categories/{id}/delete', name: 'admin_content_blog_category_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteCategory(Request $request, int $id): RedirectResponse
    {
        if ($this->isCsrfTokenValid('admin_content_blog_categories', (string) $request->request->get('_token'))) {
            $ctx = $this->contexts->resolve($request);
            $this->blog->deleteCategory($ctx->storeId, $id);
            $this->addFlash('success', CanonicalUiText::get('admin.blog.category_deleted'));
        }
        return $this->redirectToRoute('admin_content_blog_categories');
    }

    /** @param array<string,mixed> $article */
    private function form(int $storeId, string $locale, ?int $id, array $article): Response
    {
        $locales = $this->db->fetchFirstColumn('SELECT locale_code FROM mc_store_locale WHERE store_id = ? AND enabled = 1 ORDER BY locale_code', [$storeId]);
        $translated = $id !== null ? $this->db->fetchFirstColumn('SELECT locale FROM mc_content_translation WHERE content_id = ?', [$id]) : [];
        $stored = $id !== null ? (string) $this->db->fetchOne('SELECT status FROM mc_content_entry WHERE id = ?', [$id]) : '';
        return $this->render('@storefront/admin/content/blog_form.html.twig', [
            'article' => $article,
            'article_id' => $id,
            'locale' => $locale,
            'locales' => array_map('strval', $locales),
            'translated' => array_map('strval', $translated),
            'lang_tabs' => $id !== null ? $this->blogTabs($storeId, $locale, $id, array_map('strval', $translated)) : [],
            'categories' => $this->blog->categories($storeId, $locale),
            'stored_status' => $stored,
        ]);
    }

    /**
     * @param list<string> $translated
     * @return list<array<string,mixed>>
     */
    private function blogTabs(int $storeId, string $locale, int $id, array $translated): array
    {
        $done = [];
        foreach ($translated as $code) {
            $done[$code] = true;
        }
        $tabs = [];
        foreach ($this->languageTabs->tabs($storeId, $locale, $done) as $tab) {
            $tabs[] = $tab + ['href' => $tab['current'] ? '' : $this->generateUrl('admin_content_blog_edit', ['id' => $id, 'locale' => $tab['code']])];
        }

        return $tabs;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function merge(array $base, array $in): array
    {
        foreach (['title', 'slug', 'excerpt', 'body_html', 'meta_title', 'meta_description', 'status', 'published_at', 'cover_alt', 'author_name', 'canonical_url', 'tags', 'product_skus', 'image_size', 'image_align'] as $key) {
            if (array_key_exists($key, $in)) {
                $base[$key] = (string) $in[$key];
            }
        }
        $base['category_id'] = (int) ($in['category_id'] ?? 0);
        $base['featured'] = !empty($in['featured']);
        $base['noindex'] = !empty($in['noindex']);
        return $base;
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'id' => null, 'public_id' => '', 'status' => 'draft', 'published_at' => '', 'has_translation' => false, 'title' => '', 'excerpt' => '',
            'body_html' => '', 'meta_title' => '', 'meta_description' => '', 'slug' => '', 'path' => '', 'category_id' => 0, 'cover_url' => '',
            'cover_alt' => '', 'image_size' => 'm', 'image_align' => 'none', 'author_name' => '', 'featured' => false, 'noindex' => false, 'canonical_url' => '', 'tags' => '', 'product_skus' => '',
        ];
    }
}
