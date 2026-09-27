<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Http;

use Commerce\Modules\Forum\Application\ForumService;
use Commerce\Modules\Security\Spam\PublicFormSpamGuard;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ForumController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly ForumService $forum,
        private readonly PublicFormSpamGuard $spamGuard,
    ) {
    }

    #[Route('/forum', name: 'storefront_forum_index', methods: ['GET'], priority: 250)]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        return $this->render('@storefront/forum/index.html.twig', [
            'page_title' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.forum'),
            'store_name' => $context->storeName,
            'boards' => $this->forum->boards($context->storeId),
            'seo_head' => [
                'canonical' => $request->getSchemeAndHttpHost() . '/forum',
                'robots' => 'index,follow,max-image-preview:large',
            ],
        ]);
    }

    #[Route('/forum/{slug}', name: 'storefront_forum_board', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{0,159}'], priority: 240)]
    public function board(Request $request, string $slug): Response
    {
        $context = $this->contexts->resolve($request);
        $board = $this->forum->board($context->storeId, $slug);
        if ($board === null) {
            throw $this->createNotFoundException();
        }
        $page = max(1, $request->query->getInt('page', 1));
        return $this->render('@storefront/forum/board.html.twig', [
            'page_title' => (string) $board['name'],
            'store_name' => $context->storeName,
            'board' => $board,
            'topics' => $this->forum->topics((int) $board['id'], $page),
            'page' => $page,
            'form_rendered_at' => time(),
            'seo_head' => [
                'canonical' => $request->getSchemeAndHttpHost() . '/forum/' . $slug . ($page > 1 ? '?page=' . $page : ''),
                'robots' => 'index,follow,max-image-preview:large',
            ],
        ]);
    }

    #[Route('/forum/{slug}/topics', name: 'storefront_forum_topic_create', methods: ['POST'], requirements: ['slug' => '[a-z0-9][a-z0-9-]{0,159}'], priority: 260)]
    public function createTopic(Request $request, string $slug): Response
    {
        $context = $this->contexts->resolve($request);
        $board = $this->forum->board($context->storeId, $slug);
        if ($board === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('forum_topic_' . (int) $board['id'], (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.sesiia_formy_zavershylas_povtorit_vidpravlennia'));
            return $this->redirectToRoute('storefront_forum_board', ['slug' => $slug]);
        }
        if (!$this->allowSessionPost($request)) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.zabahato_povidomlen_za_korotkyi_chas_sprobuite_trokh'));
            return $this->redirectToRoute('storefront_forum_board', ['slug' => $slug]);
        }
        $spam = $this->spamGuard->check(
            (string) $request->request->get('company', ''),
            $request->request->getInt('rendered_at', 0),
            time(),
            strlen((string) $request->getContent()),
        );
        if (!$spam->allowed) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.povidomlennia_ne_proishlo_antyspam_perevirku'));
            return $this->redirectToRoute('storefront_forum_board', ['slug' => $slug]);
        }
        try {
            $this->forum->createTopic(
                $context->storeId,
                $slug,
                (string) $request->request->get('author_name', ''),
                (string) $request->request->get('title', ''),
                (string) $request->request->get('body', ''),
            );
            $this->rememberSessionPost($request);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.temu_nadislano_na_moderatsiiu_pislia_perevirky_vona_'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
        }
        return $this->redirectToRoute('storefront_forum_board', ['slug' => $slug]);
    }

    #[Route('/forum/t/{id}/{slug}', name: 'storefront_forum_topic', methods: ['GET'], requirements: ['id' => '\\d+', 'slug' => '[a-z0-9][a-z0-9-]{0,239}'], priority: 270)]
    public function topic(Request $request, int $id, string $slug): Response
    {
        $context = $this->contexts->resolve($request);
        $topic = $this->forum->topic($context->storeId, $id);
        if ($topic === null) {
            throw $this->createNotFoundException();
        }
        if ($slug !== (string) $topic['slug']) {
            return $this->redirectToRoute('storefront_forum_topic', ['id' => $id, 'slug' => (string) $topic['slug']], 301);
        }
        return $this->render('@storefront/forum/topic.html.twig', [
            'page_title' => (string) $topic['title'],
            'store_name' => $context->storeName,
            'topic' => $topic,
            'posts' => $this->forum->posts($id),
            'form_rendered_at' => time(),
            'seo_head' => [
                'canonical' => $request->getSchemeAndHttpHost() . '/forum/t/' . $id . '/' . $slug,
                'robots' => 'index,follow,max-image-preview:large',
            ],
        ]);
    }

    #[Route('/forum/t/{id}/{slug}/reply', name: 'storefront_forum_reply', methods: ['POST'], requirements: ['id' => '\\d+', 'slug' => '[a-z0-9][a-z0-9-]{0,239}'], priority: 280)]
    public function reply(Request $request, int $id, string $slug): Response
    {
        $context = $this->contexts->resolve($request);
        $topic = $this->forum->topic($context->storeId, $id);
        if ($topic === null) {
            throw $this->createNotFoundException();
        }
        $target = ['id' => $id, 'slug' => (string) $topic['slug']];
        if (!$this->isCsrfTokenValid('forum_reply_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.sesiia_formy_zavershylas_povtorit_vidpravlennia'));
            return $this->redirectToRoute('storefront_forum_topic', $target);
        }
        if (!$this->allowSessionPost($request)) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.zabahato_povidomlen_za_korotkyi_chas_sprobuite_trokh'));
            return $this->redirectToRoute('storefront_forum_topic', $target);
        }
        $spam = $this->spamGuard->check(
            (string) $request->request->get('company', ''),
            $request->request->getInt('rendered_at', 0),
            time(),
            strlen((string) $request->getContent()),
        );
        if (!$spam->allowed) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.povidomlennia_ne_proishlo_antyspam_perevirku'));
            return $this->redirectToRoute('storefront_forum_topic', $target);
        }
        try {
            $this->forum->createReply(
                $context->storeId,
                $id,
                (string) $request->request->get('author_name', ''),
                (string) $request->request->get('body', ''),
            );
            $this->rememberSessionPost($request);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.vidpovid_nadislano_na_moderatsiiu'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
        }
        return $this->redirectToRoute('storefront_forum_topic', $target);
    }

    private function allowSessionPost(Request $request): bool
    {
        $session = $request->getSession();
        $now = time();
        $timestamps = array_values(array_filter((array) $session->get('forum_post_times', []), static fn (mixed $time): bool => is_int($time) && $time >= $now - 600));
        return count($timestamps) < 5;
    }

    private function rememberSessionPost(Request $request): void
    {
        $session = $request->getSession();
        $now = time();
        $timestamps = array_values(array_filter((array) $session->get('forum_post_times', []), static fn (mixed $time): bool => is_int($time) && $time >= $now - 600));
        $timestamps[] = $now;
        $session->set('forum_post_times', $timestamps);
    }

    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException) {
            $message = preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()) ?: \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.ne_vdalosia_zberehty_povidomlennia');
            return mb_substr($message, 0, 400, 'UTF-8');
        }
        return \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.povidomlennia_ne_zberezheno_sprobuite_shche_raz');
    }
}
