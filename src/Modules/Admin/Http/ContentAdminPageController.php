<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Application\ContentLanguageTabs;
use Commerce\Modules\Ai\Application\AiTaskService;
use Commerce\Modules\Content\Application\InformationPageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Information pages: list, create, edit in every store language, duplicate, publish/unpublish, delete. */
final class ContentAdminPageController extends AbstractController
{
    private const REF = '(?!new$)[A-Za-z0-9_-]+';
    private const TEXT_FIELDS = ['title', 'excerpt', 'body_html', 'meta_title', 'meta_description'];

    public function __construct(
        private readonly AdminContextResolver $context,
        private readonly InformationPageService $pages,
        private readonly ContentLanguageTabs $tabs,
        private readonly AiTaskService $ai,
    ) {
    }

    #[Route('/admin/content/pages', name: 'admin_content_pages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->context->resolve($request);
        $filters = ['q' => trim((string) $request->query->get('q', '')), 'status' => (string) $request->query->get('status', '')];

        return $this->render('@storefront/admin/content/pages.html.twig', [
            'items' => $this->pages->list($context->storeId, $context->locale, $filters),
            'filters' => $filters,
            'locale' => $context->locale,
        ]);
    }

    #[Route('/admin/content/pages/new', name: 'admin_content_page_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $context = $this->context->resolve($request);
        $locale = $this->defaultLocale($context->storeId, $context->locale);
        if ($request->isMethod('POST')) {
            $result = $this->persist($request, $context->storeId, $locale, null);
            if ($result instanceof RedirectResponse) {
                return $result;
            }
            $values = $result;
        } else {
            $values = ['title' => '', 'excerpt' => '', 'body_html' => '', 'meta_title' => '', 'meta_description' => '', 'status' => 'draft', 'slug' => '', 'page_group' => 'company', 'show_in_footer' => true, 'show_in_menu' => false, 'sort_order' => 100, 'noindex' => false, 'canonical_url' => '', 'og_asset_id' => 0, 'og_url' => ''];
        }

        return $this->form($context->storeId, $locale, null, $values + ['is_system' => false, 'system_key' => null, 'has_translation' => false, 'path' => null, 'default_text' => null, 'translated' => [], 'ref' => 'new', 'id' => 0, 'public_id' => '']);
    }

    #[Route('/admin/content/pages/{ref}', name: 'admin_content_page_edit', methods: ['GET', 'POST'], requirements: ['ref' => self::REF])]
    public function edit(Request $request, string $ref): Response
    {
        $context = $this->context->resolve($request);
        $page = $this->pages->find($context->storeId, $ref, $context->locale);
        if ($page === null) {
            throw $this->createNotFoundException();
        }
        if ($request->isMethod('POST')) {
            $result = $this->persist($request, $context->storeId, $context->locale, (int) $page['id'], (bool) $page['is_system']);
            if ($result instanceof RedirectResponse) {
                return $result;
            }
            $page = array_merge($page, $result);
        } elseif ($request->query->get('prefill') === 'default' && !$page['has_translation'] && is_array($page['default_text'])) {
            $page = array_merge($page, $page['default_text'], ['prefilled' => 'default']);
        }

        return $this->form($context->storeId, $context->locale, (int) $page['id'], $page);
    }

    /** Fills a language that has no text yet with an AI translation of the default language; nothing is saved until the editor presses Save. */
    #[Route('/admin/content/pages/{ref}/translate', name: 'admin_content_page_translate', methods: ['POST'], requirements: ['ref' => self::REF])]
    public function translate(Request $request, string $ref): Response
    {
        $context = $this->context->resolve($request);
        $page = $this->pages->find($context->storeId, $ref, $context->locale);
        if ($page === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('admin_content_page_' . $ref, (string) $request->request->get('_token'))) {
            $this->addFlash('error', CanonicalUiText::get('common.security.invalid_csrf'));

            return $this->redirectToRoute('admin_content_page_edit', ['ref' => $ref, 'locale' => $context->locale]);
        }
        $source = is_array($page['default_text']) ? $page['default_text'] : null;
        $provider = (string) $request->request->get('provider', '');
        if ($source === null || $provider === '') {
            $this->addFlash('error', CanonicalUiText::get('admin.ai.error_input'));

            return $this->redirectToRoute('admin_content_page_edit', ['ref' => $ref, 'locale' => $context->locale]);
        }
        $admin = $this->getUser();
        $translated = [];
        try {
            foreach (self::TEXT_FIELDS as $field) {
                $text = trim((string) ($source[$field] ?? ''));
                $translated[$field] = $text === '' ? '' : ($this->ai->run($context->storeId, $admin !== null ? $admin->getUserIdentifier() : 'admin', 'translate', $provider, ['text' => $text, 'target' => $context->locale], $context->locale)['fields']['text'] ?? '');
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_content_page_edit', ['ref' => $ref, 'locale' => $context->locale]);
        }
        $this->addFlash('success', CanonicalUiText::get('admin.pages.translated_draft'));

        return $this->form($context->storeId, $context->locale, (int) $page['id'], array_merge($page, $translated, ['prefilled' => 'ai']));
    }

    #[Route('/admin/content/pages/{ref}/toggle', name: 'admin_content_page_toggle', methods: ['POST'], requirements: ['ref' => self::REF])]
    public function toggle(Request $request, string $ref): RedirectResponse
    {
        $context = $this->context->resolve($request);
        if ($this->isCsrfTokenValid('admin_content_pages', (string) $request->request->get('_token'))) {
            try {
                $page = $this->pages->find($context->storeId, $ref, $context->locale) ?? throw new \InvalidArgumentException(CanonicalUiText::get('admin.pages.error.not_found'));
                $status = $this->pages->toggle($context->storeId, (int) $page['id']);
                $this->addFlash('success', CanonicalUiText::get($status === 'published' ? 'admin.pages.published' : 'admin.pages.unpublished'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        } else {
            $this->addFlash('error', CanonicalUiText::get('common.security.invalid_csrf'));
        }

        return $this->redirectToRoute('admin_content_pages');
    }

    #[Route('/admin/content/pages/{ref}/duplicate', name: 'admin_content_page_duplicate', methods: ['POST'], requirements: ['ref' => self::REF])]
    public function duplicate(Request $request, string $ref): RedirectResponse
    {
        $context = $this->context->resolve($request);
        if (!$this->isCsrfTokenValid('admin_content_pages', (string) $request->request->get('_token'))) {
            $this->addFlash('error', CanonicalUiText::get('common.security.invalid_csrf'));

            return $this->redirectToRoute('admin_content_pages');
        }
        try {
            $page = $this->pages->find($context->storeId, $ref, $context->locale) ?? throw new \InvalidArgumentException(CanonicalUiText::get('admin.pages.error.not_found'));
            $admin = $this->getUser();
            $copy = $this->pages->duplicate($context->storeId, (int) $page['id'], $admin !== null ? $admin->getUserIdentifier() : 'admin');
            $this->addFlash('success', CanonicalUiText::get('admin.pages.duplicated'));

            return $this->redirectToRoute('admin_content_page_edit', ['ref' => (string) $copy]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_content_pages');
        }
    }

    #[Route('/admin/content/pages/{ref}/delete', name: 'admin_content_page_delete', methods: ['POST'], requirements: ['ref' => self::REF])]
    public function delete(Request $request, string $ref): RedirectResponse
    {
        $context = $this->context->resolve($request);
        if ($this->isCsrfTokenValid('admin_content_pages', (string) $request->request->get('_token'))) {
            try {
                $page = $this->pages->find($context->storeId, $ref, $context->locale) ?? throw new \InvalidArgumentException(CanonicalUiText::get('admin.pages.error.not_found'));
                $this->pages->delete($context->storeId, (int) $page['id']);
                $this->addFlash('success', CanonicalUiText::get('admin.pages.deleted'));
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        } else {
            $this->addFlash('error', CanonicalUiText::get('common.security.invalid_csrf'));
        }

        return $this->redirectToRoute('admin_content_pages');
    }

    /** @return RedirectResponse|array<string,mixed> the redirect after a successful save, otherwise the submitted values to show again */
    private function persist(Request $request, int $storeId, string $locale, ?int $id, bool $isSystem = false): RedirectResponse|array
    {
        $key = $id === null ? 'new' : (string) ($request->attributes->get('ref') ?? $id);
        $input = $request->request->all();
        $values = [
            'title' => (string) ($input['title'] ?? ''), 'excerpt' => (string) ($input['excerpt'] ?? ''), 'body_html' => (string) ($input['body_html'] ?? ''),
            'meta_title' => (string) ($input['meta_title'] ?? ''), 'meta_description' => (string) ($input['meta_description'] ?? ''), 'status' => (string) ($input['status'] ?? 'draft'),
            'slug' => (string) ($input['slug'] ?? ''), 'page_group' => (string) ($input['page_group'] ?? 'company'),
            'show_in_footer' => $request->request->getBoolean('show_in_footer'), 'show_in_menu' => $request->request->getBoolean('show_in_menu'),
            'sort_order' => (int) ($input['sort_order'] ?? 100), 'noindex' => (string) ($input['robots'] ?? 'index') === 'noindex',
            'canonical_url' => (string) ($input['canonical_url'] ?? ''), 'og_asset_id' => (int) ($input['og_asset_id'] ?? 0), 'og_url' => '',
        ];
        if (!$this->isCsrfTokenValid('admin_content_page_' . $key, (string) $request->request->get('_token'))) {
            $this->addFlash('error', CanonicalUiText::get('admin.pages.error.session'));

            return $values;
        }
        try {
            $admin = $this->getUser();
            $savedId = $this->pages->save($storeId, $locale, $id, $values + ['robots' => $values['noindex'] ? 'noindex' : 'index', 'placement_present' => $request->request->get('placement_present')], $admin !== null ? $admin->getUserIdentifier() : 'admin');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $values;
        }
        $this->addFlash('success', CanonicalUiText::get('admin.pages.saved'));
        $saved = $this->pages->find($storeId, $isSystem ? $key : (string) $savedId, $locale);

        return $this->redirectToRoute('admin_content_page_edit', ['ref' => $saved['ref'] ?? (string) $savedId, 'locale' => $locale]);
    }

    /** @param array<string,mixed> $page */
    private function form(int $storeId, string $locale, ?int $id, array $page): Response
    {
        $tabs = [];
        if ($id !== null) {
            foreach ($this->tabs->tabs($storeId, $locale, (array) ($page['translated'] ?? [])) as $tab) {
                $tabs[] = $tab + ['href' => $tab['current'] ? '' : $this->generateUrl('admin_content_page_edit', ['ref' => (string) $page['ref'], 'locale' => $tab['code']])];
            }
        }
        $isDefault = $locale === $this->defaultLocale($storeId, $locale);
        $ref = $id === null ? 'new' : (string) $page['ref'];

        return $this->render('@storefront/admin/content/page_form.html.twig', [
            'page' => $page,
            'locale' => $locale,
            'is_default_locale' => $isDefault,
            'lang_tabs' => $tabs,
            'groups' => InformationPageService::GROUPS,
            'csrf_id' => 'admin_content_page_' . $ref,
            'ai_default_text' => $isDefault ? null : ($page['default_text'] ?? null),
        ]);
    }

    private function defaultLocale(int $storeId, string $fallback): string
    {
        $tabs = $this->tabs->tabs($storeId, $fallback);
        foreach ($tabs as $tab) {
            if ($tab['is_default']) {
                return $tab['code'];
            }
        }

        return $fallback;
    }
}
