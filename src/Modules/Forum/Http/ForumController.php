<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Http;

use Commerce\Modules\Forum\Application\ForumService;
use Commerce\Modules\Forum\Application\ForumCommunityService;
use Commerce\Modules\Forum\Application\ForumEngagementService;
use Commerce\Modules\Forum\Application\ForumAccessPolicy;
use Commerce\Modules\Forum\Application\ForumProfileService;
use Commerce\Modules\Forum\Application\ForumDirectMessageService;
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
        private readonly ForumEngagementService $engagement,
        private readonly ForumAccessPolicy $accessPolicy,
        private readonly ForumProfileService $profiles,
        private readonly ForumDirectMessageService $directMessages,
        private readonly PublicFormSpamGuard $spamGuard,
        private readonly \Commerce\Modules\Security\Captcha\CaptchaVerifier $captcha,
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
            'latest_topics' => $this->forum->latestTopics($context->storeId),
            'overview' => $this->engagement->overview($context->storeId),
            'unread_count' => $this->presence($context->storeId),
            'seo_head' => [
                'canonical' => $request->getSchemeAndHttpHost() . '/forum',
                'robots' => 'index,follow,max-image-preview:large',
            ],
        ]);
    }

    #[Route('/forum/rules', name: 'storefront_forum_rules', methods: ['GET'], priority: 310)]
    public function rules(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        return $this->render('@storefront/forum/rules.html.twig', [
            'page_title' => 'Forum rules',
            'store_name' => $context->storeName,
            'seo_head' => [
                'canonical' => $request->getSchemeAndHttpHost() . '/forum/rules',
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
        $user = $this->getUser();
        $customerId = $user instanceof CustomerUser ? $user->id() : null;
        $sort = in_array((string) $request->query->get('sort', 'activity'), ['activity', 'new', 'replies', 'views'], true) ? (string) $request->query->get('sort', 'activity') : 'activity';
        $this->presence($context->storeId);
        return $this->render('@storefront/forum/board.html.twig', [
            'page_title' => (string) $board['name'],
            'store_name' => $context->storeName,
            'board' => $board,
            'children' => array_values(array_filter($this->forum->boards($context->storeId), static fn (array $b): bool => (int) ($b['parent_id'] ?? 0) === (int) $board['id'])),
            'sort' => $sort,
            'topics' => $this->forum->topics((int) $board['id'], $page, 30, $customerId, $sort),
            'unread_count' => $customerId !== null ? $this->community->unreadCount($context->storeId, $customerId) : 0,
            'forum_nickname' => $customerId !== null ? $this->profiles->nickname($context->storeId, $customerId) : null,
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
        if (!$spam->allowed || !$this->captcha->verify($request, 'forum')) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.povidomlennia_ne_proishlo_antyspam_perevirku'));
            return $this->redirectToRoute('storefront_forum_board', ['slug' => $slug]);
        }
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('flash.forum_sign_in_topic'));
            return $this->redirectToRoute('customer_login');
        }
        try {
            $this->accessPolicy->assertCanParticipate($context->storeId, $user->id());
            $this->forum->createTopic(
                $context->storeId,
                $slug,
                $user->id(),
                $this->profiles->nickname($context->storeId, $user->id()),
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
        $pageSize = 30;
        $pages = max(1, (int) ceil(((int) ($topic['post_count'] ?? 0)) / $pageSize));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $posts = $this->forum->posts($context->storeId, $id, $page, $pageSize, $customerId);
        $this->presence($context->storeId);
        $cards = $this->engagement->memberCards($context->storeId, array_map(static fn (array $p): int => (int) ($p['customer_id'] ?? 0), $posts));
        if ($customerId !== null) {
            $this->community->markRead($context->storeId, $id, $customerId);
        }
        return $this->render('@storefront/forum/topic.html.twig', [
            'page_title' => (string) $topic['title'],
            'store_name' => $context->storeName,
            'topic' => $topic,
            'posts' => $posts,
            'cards' => $cards,
            'mentions' => $this->mentionMap($context->storeId, $posts),
            'page' => $page,
            'pages' => $pages,
            'current_customer_id' => $customerId,
            'forum_nickname' => $customerId !== null ? $this->profiles->nickname($context->storeId, $customerId) : null,
            'is_subscribed' => $customerId !== null ? $this->community->isSubscribed($context->storeId, $id, $customerId) : false,
            'form_rendered_at' => time(),
            'seo_head' => [
                'canonical' => $request->getSchemeAndHttpHost() . '/forum/t/' . $id . '/' . $slug . ($page > 1 ? '?page=' . $page : ''),
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
        if (!$spam->allowed || !$this->captcha->verify($request, 'forum')) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.forum.http.forumcontroller.povidomlennia_ne_proishlo_antyspam_perevirku'));
            return $this->redirectToRoute('storefront_forum_topic', $target);
        }
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('flash.forum_sign_in_reply'));
            return $this->redirectToRoute('customer_login');
        }
        try {
            $this->accessPolicy->assertCanParticipate($context->storeId, $user->id());
            $this->forum->createReply(
                $context->storeId,
                $id,
                $user->id(),
                $this->profiles->nickname($context->storeId, $user->id()),
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
        $member = $this->profiles->publicProfile($context->storeId, $id);
        if ($member === null) {
            throw $this->createNotFoundException();
        }
        $member['stats'] = $this->community->memberStats($context->storeId, $id);
        $viewer = $this->getUser();
        return $this->render('@storefront/forum/member.html.twig', [
            'page_title' => (string) $member['nickname'],
            'store_name' => $context->storeName,
            'member' => $member,
            'can_message' => $viewer instanceof CustomerUser && $viewer->id() !== $id && (int)($member['allow_private_messages'] ?? 0) === 1 && $this->accessPolicy->canParticipate($context->storeId, $viewer->id()),
            'activity' => $this->community->memberRecentActivity($context->storeId, $id),
            'seo_head' => [
                'canonical' => $request->getSchemeAndHttpHost() . '/forum/member/' . $id,
                'robots' => 'noindex,follow',
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

    #[Route('/forum/profile', name: 'storefront_forum_profile', methods: ['GET', 'POST'], priority: 296)]
    public function profile(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forum_profile', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $this->profiles->update(
                    $context->storeId,
                    $user->id(),
                    (string) $request->request->get('nickname', ''),
                    (string) $request->request->get('bio', ''),
                    $request->request->getBoolean('show_email'),
                    $request->request->getBoolean('show_phone'),
                    $request->request->getBoolean('allow_private_messages'),
                );
                $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('flash.forum_profile_updated'));
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
            return $this->redirectToRoute('storefront_forum_profile');
        }
        return $this->render('@storefront/forum/profile.html.twig', [
            'page_title' => 'Forum profile',
            'store_name' => $context->storeName,
            'profile' => $this->profiles->getOrCreate($context->storeId, $user->id()),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/forum/messages', name: 'storefront_forum_messages', methods: ['GET'], priority: 296)]
    public function messages(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        return $this->render('@storefront/forum/messages.html.twig', [
            'page_title' => 'Private messages',
            'store_name' => $context->storeName,
            'threads' => $this->directMessages->threads($context->storeId, $user->id()),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/forum/messages/{id}', name: 'storefront_forum_message_thread', methods: ['GET'], requirements: ['id' => '\\d+'], priority: 297)]
    public function messageThread(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        $data = $this->directMessages->thread($context->storeId, $id, $user->id());
        if ($data === null) {
            throw $this->createNotFoundException();
        }
        return $this->render('@storefront/forum/message_thread.html.twig', [
            'page_title' => 'Private conversation',
            'store_name' => $context->storeName,
            'thread' => $data['thread'],
            'messages' => $data['messages'],
            'current_customer_id' => $user->id(),
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/forum/member/{id}/message', name: 'storefront_forum_message_start', methods: ['POST'], requirements: ['id' => '\\d+'], priority: 297)]
    public function startMessage(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        if (!$this->isCsrfTokenValid('forum_message_start_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->directMessages->send($context->storeId, $user->id(), $id, (string) $request->request->get('body', ''));
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('flash.pm_sent'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('storefront_forum_messages');
    }

    #[Route('/forum/messages/{id}/reply', name: 'storefront_forum_message_reply', methods: ['POST'], requirements: ['id' => '\\d+'], priority: 297)]
    public function replyMessage(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        if (!$this->isCsrfTokenValid('forum_message_reply_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $data = $this->directMessages->thread($context->storeId, $id, $user->id());
        if ($data === null) {
            throw $this->createNotFoundException();
        }
        try {
            $this->directMessages->send($context->storeId, $user->id(), (int) $data['thread']['other_customer_id'], (string) $request->request->get('body', ''));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('storefront_forum_message_thread', ['id' => $id]);
    }

    #[Route('/forum/messages/{threadId}/block/{memberId}', name: 'storefront_forum_message_block', methods: ['POST'], requirements: ['threadId' => '\\d+', 'memberId' => '\\d+'], priority: 297)]
    public function blockMember(Request $request, int $threadId, int $memberId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        if (!$this->isCsrfTokenValid('forum_block_' . $memberId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->directMessages->block($context->storeId, $user->id(), $memberId);
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('flash.member_blocked'));
        return $this->redirectToRoute('storefront_forum_message_thread', ['id' => $threadId]);
    }

    #[Route('/forum/messages/{threadId}/report/{messageId}', name: 'storefront_forum_message_report', methods: ['POST'], requirements: ['threadId' => '\\d+', 'messageId' => '\\d+'], priority: 297)]
    public function reportMessage(Request $request, int $threadId, int $messageId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        if (!$this->isCsrfTokenValid('forum_dm_report_' . $messageId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->directMessages->report($context->storeId, $messageId, $user->id(), (string) $request->request->get('reason', 'other'), (string) $request->request->get('details', ''));
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('flash.pm_reported'));
        return $this->redirectToRoute('storefront_forum_message_thread', ['id' => $threadId]);
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
        $user = $this->requireForumParticipant($context->storeId);
        if (!$this->isCsrfTokenValid('forum_subscription_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $enabled = (string) $request->request->get('enabled', '1') === '1';
        $this->community->setSubscription($context->storeId, $id, $user->id(), $enabled);
        return $this->redirectToRoute('storefront_forum_topic', ['id' => $id, 'slug' => $slug]);
    }

    #[Route('/forum/t/{id}/{slug}/posts/{postId}/vote', name: 'storefront_forum_post_vote', methods: ['POST'], requirements: ['id' => '\\d+', 'postId' => '\\d+'], priority: 290)]
    public function vote(Request $request, int $id, string $slug, int $postId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        if (!$this->isCsrfTokenValid('forum_vote_' . $postId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->engagement->vote($context->storeId, $postId, $user->id(), (string) $request->request->get('direction', 'up'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('storefront_forum_topic', ['id' => $id, 'slug' => $slug]) . '#post-' . $postId, 303);
    }

    #[Route('/forum/t/{id}/{slug}/posts/{postId}/solution', name: 'storefront_forum_post_solution', methods: ['POST'], requirements: ['id' => '\\d+', 'postId' => '\\d+'], priority: 290)]
    public function solution(Request $request, int $id, string $slug, int $postId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        if (!$this->isCsrfTokenValid('forum_solution_' . $postId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->engagement->setSolution($context->storeId, $id, $postId, $user->id());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('storefront_forum_topic', ['id' => $id, 'slug' => $slug]) . '#post-' . $postId, 303);
    }

    #[Route('/forum/t/{id}/{slug}/posts/{postId}/report', name: 'storefront_forum_post_report', methods: ['POST'], requirements: ['id' => '\\d+', 'postId' => '\\d+'], priority: 290)]
    public function report(Request $request, int $id, string $slug, int $postId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
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
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('flash.forum_report_sent'));
        return $this->redirectToRoute('storefront_forum_topic', ['id' => $id, 'slug' => $slug], 303);
    }

    #[Route('/forum/t/{id}/{slug}/posts/{postId}/edit', name: 'storefront_forum_post_edit', methods: ['POST'], requirements: ['id' => '\\d+', 'postId' => '\\d+'], priority: 290)]
    public function editPost(Request $request, int $id, string $slug, int $postId): Response
    {
        $context = $this->contexts->resolve($request);
        $user = $this->requireForumParticipant($context->storeId);
        if (!$this->isCsrfTokenValid('forum_edit_' . $postId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->community->editOwnPost($context->storeId, $postId, $user->id(), (string) $request->request->get('body', ''));
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('flash.post_updated'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('storefront_forum_topic', ['id' => $id, 'slug' => $slug], 303);
    }

    /** Marks a signed-in member as present and returns the number of topics with news for them. */
    private function presence(int $storeId): int
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            return 0;
        }
        $this->engagement->touch($user->id());

        return $this->community->unreadCount($storeId, $user->id());
    }

    /**
     * @param list<array<string,mixed>> $posts
     * @return array<string,string> lower-case nickname => member page
     */
    private function mentionMap(int $storeId, array $posts): array
    {
        $names = [];
        foreach ($posts as $post) {
            if (preg_match_all('/(?:^|[\s(])@([\p{L}\p{N}_-]{2,64})/u', (string) ($post['body_text'] ?? ''), $found) > 0) {
                foreach ($found[1] as $name) {
                    $names[mb_strtolower($name, 'UTF-8')] = true;
                }
            }
        }

        return $this->community->memberUrls($storeId, array_keys($names), fn (int $id): string => $this->generateUrl('storefront_forum_member', ['id' => $id]));
    }

    private function requireForumParticipant(int $storeId): CustomerUser
    {
        $user = $this->requireCustomer();
        try {
            $this->accessPolicy->assertCanParticipate($storeId, $user->id());
        } catch (\DomainException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }
        return $user;
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
