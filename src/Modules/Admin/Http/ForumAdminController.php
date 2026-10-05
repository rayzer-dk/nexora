<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Forum\Application\ForumService;
use Commerce\Modules\Forum\Application\ForumCommunityService;
use Commerce\Modules\Forum\Application\ForumNotificationService;
use Commerce\Modules\Forum\Application\ForumDirectMessageService;
use Commerce\Modules\Forum\Application\ForumModerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ForumAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly ForumService $forum,
        private readonly ForumCommunityService $community,
        private readonly ForumNotificationService $notifications,
        private readonly ForumDirectMessageService $directMessages,
        private readonly ForumModerationService $moderation,
    ) {
    }

    #[Route('/admin/forum', name: 'admin_forum', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $queue = ['boards' => [], 'topics' => [], 'posts' => [], 'published_topics' => [], 'reports' => [], 'dm_reports' => [], 'bans' => []];
        try {
            $queue = $this->forum->moderationQueue($context->storeId);
            $queue['reports'] = $this->community->openReports($context->storeId);
            $queue['dm_reports'] = $this->directMessages->openReports($context->storeId);
            $queue['bans'] = $this->moderation->activeBans($context->storeId);
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.forum_tymchasovo_nedostupnyi') . $this->safeMessage($e));
        }
        return $this->render('@storefront/admin/forum/index.html.twig', [
            'platform_version' => PlatformVersion::VERSION,
            'queue' => $queue,
        ]);
    }

    #[Route('/admin/forum/boards', name: 'admin_forum_board_create', methods: ['POST'])]
    public function createBoard(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_board_create', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));
            return $this->redirectToRoute('admin_forum');
        }
        try {
            $this->forum->createBoard(
                $context->storeId,
                (string) $request->request->get('name', ''),
                (string) $request->request->get('slug', ''),
                (string) $request->request->get('description', ''),
                $request->request->getInt('sort_order', 0),
                $request->request->getInt('parent_id', 0) ?: null,
            );
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.rozdil_forumu_stvoreno'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.rozdil_ne_stvoreno') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/boards/{id}/update', name: 'admin_forum_board_update', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function updateBoard(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_board_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->forum->updateBoard($context->storeId, $id, (string) $request->request->get('name', ''), (string) $request->request->get('description', ''), $request->request->getInt('sort_order', 0), (string) $request->request->get('status', 'active'), $request->request->getInt('parent_id', 0) ?: null);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.board.saved'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/boards/{id}/delete', name: 'admin_forum_board_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function deleteBoard(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_board_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->forum->deleteBoard($context->storeId, $id);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.board.deleted'));
        } catch (Throwable $e) {
            $this->addFlash('error', $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/topics/{id}/{action}', name: 'admin_forum_topic_moderate', methods: ['POST'], requirements: ['id' => '\\d+', 'action' => 'approve|reject|lock|unlock|pin|unpin'])]
    public function moderateTopic(Request $request, int $id, string $action): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_topic_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));
            return $this->redirectToRoute('admin_forum');
        }
        try {
            $this->forum->moderateTopic($context->storeId, $id, $action);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.stan_temy_onovleno'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.diiu_ne_vykonano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/posts/{id}/{action}', name: 'admin_forum_post_moderate', methods: ['POST'], requirements: ['id' => '\\d+', 'action' => 'approve|reject'])]
    public function moderatePost(Request $request, int $id, string $action): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_post_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.ai.http.aiadmincontroller.nediisnyi_token_bezpeky'));
            return $this->redirectToRoute('admin_forum');
        }
        try {
            $this->forum->moderatePost($context->storeId, $id, $action);
            if ($action === 'approve') {
                try {
                    $this->notifications->notifyPublishedReply($context->storeId, $id);
                } catch (Throwable) {
                    // Notification delivery is asynchronous and must never roll back moderation.
                }
            }
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.povidomlennia_obrobleno'));
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.diiu_ne_vykonano') . $this->safeMessage($e));
        }
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/reports/{id}/resolve', name: 'admin_forum_report_resolve', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function resolveReport(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_report_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->community->resolveReport($context->storeId, $id);
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.report_resolved'));
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/dm-reports/{id}/resolve', name: 'admin_forum_dm_report_resolve', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function resolveDirectMessageReport(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_dm_report_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->directMessages->resolveReport($context->storeId, $id);
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.dm_report_resolved'));
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/bans', name: 'admin_forum_ban_create', methods: ['POST'])]
    public function createBan(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_ban_create', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $daysRaw = trim((string) $request->request->get('duration_days', ''));
        $days = $daysRaw === '' ? null : max(1, min(3650, (int) $daysRaw));
        try {
            $this->moderation->ban(
                $context->storeId,
                $request->request->getInt('customer_id'),
                (string) $request->request->get('reason', ''),
                $days,
            );
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.ban_applied'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/bans/{id}/revoke', name: 'admin_forum_ban_revoke', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function revokeBan(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_ban_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->moderation->revoke($context->storeId, $id);
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.ban_revoked'));
        return $this->redirectToRoute('admin_forum');
    }

    private function safeMessage(Throwable $e): string
    {
        $message = \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed');
        return mb_substr($message, 0, 400, 'UTF-8');
    }
}
