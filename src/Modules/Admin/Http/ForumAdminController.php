<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Forum\Application\ForumService;
use Commerce\Modules\Forum\Application\ForumCommunityService;
use Commerce\Modules\Forum\Application\ForumNotificationService;
use Commerce\Modules\Forum\Application\ForumDirectMessageService;
use Commerce\Modules\Forum\Application\ForumModerationService;
use Commerce\Modules\Forum\Application\ForumSettings;
use Commerce\Modules\Forum\Application\ForumStaffService;
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
        private readonly ForumSettings $settings,
        private readonly ForumStaffService $staff,
    ) {
    }

    #[Route('/admin/forum', name: 'admin_forum', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $queue = ['boards' => [], 'topics' => [], 'posts' => [], 'published_topics' => [], 'reports' => [], 'dm_reports' => [], 'bans' => [], 'warnings' => [], 'mod_log' => []];
        try {
            $queue = $this->forum->moderationQueue($context->storeId);
            $queue['reports'] = $this->community->openReports($context->storeId);
            $queue['dm_reports'] = $this->directMessages->openReports($context->storeId);
            $queue['bans'] = $this->moderation->activeBans($context->storeId);
            $queue['mod_log'] = $this->moderation->recentLog($context->storeId);
            try {
                $queue['warnings'] = $this->moderation->activeWarnings($context->storeId);
            } catch (Throwable) {
                // The warnings table appears with the pending migration.
            }
        } catch (Throwable $e) {
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.forum_tymchasovo_nedostupnyi') . $this->safeMessage($e));
        }
        return $this->render('@storefront/admin/forum/index.html.twig', [
            'platform_version' => PlatformVersion::VERSION,
            'queue' => $queue,
            'forum_settings' => $this->settings->all(),
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

    #[Route('/admin/forum/warnings', name: 'admin_forum_warning_create', methods: ['POST'])]
    public function createWarning(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_warning_create', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $banned = $this->moderation->warn(
                $context->storeId,
                $request->request->getInt('customer_id'),
                (string) $request->request->get('reason', ''),
                $request->request->getInt('points', 1),
                $request->request->getInt('valid_days', 90),
            );
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get($banned ? 'admin.forum.flash.warning_auto_ban' : 'admin.forum.flash.warning_added'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/warnings/{id}/revoke', name: 'admin_forum_warning_revoke', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function revokeWarning(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_warning_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->moderation->revokeWarning($context->storeId, $id);
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.warning_revoked'));
        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/settings', name: 'admin_forum_settings', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_settings', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->settings->save($request->request->all());
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.settings_saved'));

        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/new-topic', name: 'admin_forum_topic_create', methods: ['POST'])]
    public function createTopic(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_staff_topic', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $id = $this->staff->createTopic($context->storeId, $request->request->getInt('board_id'), (string) $request->request->get('author', ''), (string) $request->request->get('title', ''), (string) $request->request->get('body', ''), $request->request->getBoolean('pinned'));
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.topic_created'));

            return $this->redirectToRoute('admin_forum_topic_view', ['id' => $id]);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_forum');
    }

    #[Route('/admin/forum/topic/{id}', name: 'admin_forum_topic_view', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function topicView(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        $data = $this->staff->topicWithAllPosts($context->storeId, $id);
        if ($data === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@storefront/admin/forum/topic.html.twig', ['platform_version' => PlatformVersion::VERSION, 'moderators' => $this->staff->moderators($id)] + $data);
    }

    #[Route('/admin/forum/topic/{id}/reply', name: 'admin_forum_topic_reply', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function topicReply(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_staff_reply_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->staff->reply($context->storeId, $id, (string) $request->request->get('author', ''), (string) $request->request->get('body', ''));
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.reply_added'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_forum_topic_view', ['id' => $id]);
    }

    #[Route('/admin/forum/topic/{id}/{action}', name: 'admin_forum_topic_staff', methods: ['POST'], requirements: ['id' => '\\d+', 'action' => 'hide|restore|delete'])]
    public function topicStaff(Request $request, int $id, string $action): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_topic_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->staff->topicAction($context->storeId, $id, $action);
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.stan_temy_onovleno'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $action === 'delete' ? $this->redirectToRoute('admin_forum') : $this->redirectToRoute('admin_forum_topic_view', ['id' => $id]);
    }

    #[Route('/admin/forum/topic/{id}/header', name: 'admin_forum_topic_header', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function topicHeader(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_topic_tools_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->staff->setHeader($context->storeId, $id, (string) $request->request->get('header', ''));
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.header_saved'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_forum_topic_view', ['id' => $id]);
    }

    #[Route('/admin/forum/topic/{id}/slow-mode', name: 'admin_forum_topic_slow_mode', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function topicSlowMode(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_topic_tools_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->staff->setSlowMode($context->storeId, $id, $request->request->getInt('seconds'));
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('forum.runtime.slow_mode_saved'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_forum_topic_view', ['id' => $id]);
    }

    #[Route('/admin/forum/topic/{id}/moderators/add', name: 'admin_forum_topic_moderator_add', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function addModerator(Request $request, int $id): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_moderators_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->staff->addModerator($context->storeId, $id, (string) $request->request->get('who', ''));
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.moderator_added'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_forum_topic_view', ['id' => $id]);
    }

    #[Route('/admin/forum/topic/{id}/moderators/{customerId}/remove', name: 'admin_forum_topic_moderator_remove', methods: ['POST'], requirements: ['id' => '\\d+', 'customerId' => '\\d+'])]
    public function removeModerator(Request $request, int $id, int $customerId): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_moderators_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->staff->removeModerator($context->storeId, $id, $customerId);
        $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('admin.forum.flash.moderator_removed'));

        return $this->redirectToRoute('admin_forum_topic_view', ['id' => $id]);
    }

    #[Route('/admin/forum/message/{id}/{action}', name: 'admin_forum_post_staff', methods: ['POST'], requirements: ['id' => '\\d+', 'action' => 'hide|restore|delete'])]
    public function postStaff(Request $request, int $id, string $action): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('forum_post_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        $topicId = 0;
        try {
            $reason = (string) $request->request->get('reason', '');
            $topicId = $this->staff->postAction($context->storeId, $id, $action, 'admin', $reason);
            if ($action === 'hide') {
                try {
                    $this->notifications->notifyPostHidden($context->storeId, $id, $reason);
                } catch (Throwable) {
                    // The notice is a courtesy and never blocks moderation.
                }
            }
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.forumadmincontroller.povidomlennia_obrobleno'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $topicId > 0 && $this->staff->topicWithAllPosts($context->storeId, $topicId) !== null
            ? $this->redirectToRoute('admin_forum_topic_view', ['id' => $topicId])
            : $this->redirectToRoute('admin_forum');
    }

    private function safeMessage(Throwable $e): string
    {
        $message = \Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed');
        return mb_substr($message, 0, 400, 'UTF-8');
    }
}
