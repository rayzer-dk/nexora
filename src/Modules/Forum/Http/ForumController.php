<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Http;

use Commerce\Modules\Forum\Application\ForumService;
use Commerce\Modules\Forum\Application\ForumCommunityService;
use Commerce\Modules\Customer\Domain\CustomerUser;
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
        private readonly ForumCommunityService $community,
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
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Sign in to create a forum topic.');
            return $this->redirectToRoute('customer_login');
        }
        try {
            $this->forum->createTopic(
                $context->storeId,
                $slug,
                $user->id(),
                $user->displayName(),
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
        $this->community->recordView($context->storeId, $id);
        $user = $this->getUser();
        $customerId = $user instanceof CustomerUser ? $user->id() : null;
        return $this->render('@storefront/forum/topic.html.twig', [
            'page_title' => (string) $topic['title'],
            'store_name' => $context->storeName,
            'topic' => $topic,
            'posts' => $this->forum->posts($id),
            'current_customer_id' => $customerId,
            'is_subscribed' => $customerId !== null ? $this->community->isSubscribed($context->storeId, $id, $customerId) : false,
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
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', 'Sign in to reply on the forum.');
            return $this->redirectToRoute('customer_login');
        }
        try {
            $this->forum->createReply(
                $context->storeId,
                $id,
                $user->id(),
                $user->displayName(),
                (string) $request->request->get('body', ''),
            );
            $this->rememberSessionPost($request);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.vidpovid_nadislano_na_moderatsiiu'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
        }
        return $this->redirectToRoute('storefront_forum_topic', $target);
    }

    #[Route('/forum/member/{id}', name: 'storefront_forum_member', methods: ['GET'], requirements: ['id' => '\\d+'], priority: 295)]
    public function member(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $member = $this->community->member($context->storeId, $id);
        if ($member === null) {
            throw $this->createNotFoundException();
        }
        return $this->render('@storefront/forum/member.html.twig', [
            'page_title' => (string) $member['display_name'],
            'store_name' => $context->storeName,
            'member' => $member,
            'activity' => $this->community->memberRecentActivity($context->storeId, $id),
            'seo_head' => [
                'canonical' => $request->getSchemeAndHttpHost() . '/forum/member/' . $id,
                'robots' => 'index,follow,max-image-preview:large',
            ],
        ]);
    }

    #[Route('/forum/following', name: 'storefront_forum_following', methods: ['GET'], priority: 295)]
    public function following(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        return $this->render('@storefront/forum/following.html.twig', [
            'page_title' => 'Followed forum topics',
            'store_name' => $context->storeName,
            'topics' => $this->community->followedTopics($context->storeId, $user->id()),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/forum/search', name: 'storefront_forum_search', methods: ['GET'], priority: 290)]
    public function search(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $query = trim((string) $request->query->get('q', ''));
        return $this->render('@storefront/forum/search.html.twig', [
            'page_title' => 'Forum search',
            'store_name' => $context->storeName,
            'query' => $query,
            'results' => $query !== '' ? $this->community->search($context->storeId, $query) : [],
            'seo_head' => ['robots' => 'noindex,follow'],
        ]);
    }

    #[Route('/forum/t/{id}/{slug}/subscription', name: 'storefront_forum_subscription', methods: ['POST'], requirements: ['id' => '\\d+'], priority: 290)]
    public function subscription(Request $request, int $id, string $slug): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        if (!$this->isCsrfTokenValid('forum_subscription_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $enabled = (string) $request->request->get('enabled', '1') === '1';
        $this->community->setSubscription($context->storeId, $id, $user->id(), $enabled);
        return $this->redirectToRoute('storefront_forum_topic', ['id' => $id, 'slug' => $slug]);
    }

    #[Route('/forum/t/{id}/{slug}/posts/{postId}/like', name: 'storefront_forum_post_like', methods: ['POST'], requirements: ['id' => '\\d+', 'postId' => '\\d+'], priority: 290)]
    public function like(Request $request, int $id, string $slug, int $postId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        if (!$this->isCsrfTokenValid('forum_like_' . $postId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->community->toggleLike($context->storeId, $postId, $user->id());
        return $this->redirectToRoute('storefront_forum_topic', ['id' => $id, 'slug' => $slug], 303);
    }

    #[Route('/forum/t/{id}/{slug}/posts/{postId}/report', name: 'storefront_forum_post_report', methods: ['POST'], requirements: ['id' => '\\d+', 'postId' => '\\d+'], priority: 290)]
    public function report(Request $request, int $id, string $slug, int $postId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        if (!$this->isCsrfTokenValid('forum_report_' . $postId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->community->reportPost(
            $context->storeId,
            $postId,
            $user->id(),
            (string) $request->request->get('reason', 'other'),
            (string) $request->request->get('details', ''),
        );
        $this->addFlash('success', 'Report sent to moderators.');
        return $this->redirectToRoute('storefront_forum_topic', ['id' => $id, 'slug' => $slug], 303);
    }

    #[Route('/forum/t/{id}/{slug}/posts/{postId}/edit', name: 'storefront_forum_post_edit', methods: ['POST'], requirements: ['id' => '\\d+', 'postId' => '\\d+'], priority: 290)]
    public function editPost(Request $request, int $id, string $slug, int $postId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireCustomer();
        if (!$this->isCsrfTokenValid('forum_edit_' . $postId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->community->editOwnPost($context->storeId, $postId, $user->id(), (string) $request->request->get('body', ''));
            $this->addFlash('success', 'Post updated.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('storefront_forum_topic', ['id' => $id, 'slug' => $slug], 303);
    }

    private function requireCustomer(): CustomerUser
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            throw $this->createAccessDeniedException();
        }
        return $user;
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
