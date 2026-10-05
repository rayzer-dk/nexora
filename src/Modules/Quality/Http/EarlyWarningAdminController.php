<?php

declare(strict_types=1);

namespace Commerce\Modules\Quality\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Quality\Application\DailyDigestService;
use Commerce\Modules\Quality\Application\EarlyWarningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → System → Warnings and the daily letter. */
final class EarlyWarningAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly EarlyWarningService $warnings,
        private readonly DailyDigestService $digest,
    ) {
    }

    #[Route('/admin/system/early-warnings', name: 'admin_system_early_warnings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);

        return $this->render('@storefront/admin/system/early_warnings.html.twig', [
            'warnings' => $this->warnings->warnings($ctx->storeId),
            'run_out' => $this->warnings->runOut($ctx->storeId, 14),
            'certificate' => $this->warnings->certificate(),
            'settings' => $this->digest->settings(),
        ]);
    }

    #[Route('/admin/system/early-warnings/save', name: 'admin_system_early_warnings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->guard($request);
        try {
            $recipients = preg_split('/[\s,;]+/', (string) $request->request->get('recipients', '')) ?: [];
            $this->digest->save($request->request->getBoolean('enabled'), $request->request->getInt('hour', 8), $recipients, $request->request->getBoolean('only_problems'));
            $this->addFlash('success', CanonicalUiText::get('admin.digest.saved'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_system_early_warnings');
    }

    #[Route('/admin/system/early-warnings/test', name: 'admin_system_early_warnings_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $this->guard($request);
        $this->digest->sendIfDue(true);
        $this->addFlash('success', CanonicalUiText::get('admin.digest.test_sent'));

        return $this->redirectToRoute('admin_system_early_warnings');
    }

    #[Route('/admin/system/early-warnings/certificate', name: 'admin_system_early_warnings_certificate', methods: ['POST'])]
    public function certificate(Request $request): Response
    {
        $this->guard($request);
        $r = $this->warnings->refreshCertificate();
        $this->addFlash($r === null ? 'warning' : 'success', $r === null ? CanonicalUiText::get('admin.warn.cert_unavailable') : CanonicalUiText::get('admin.warn.cert_checked', ['host' => $r['host'], 'days' => (string) $r['days']]));

        return $this->redirectToRoute('admin_system_early_warnings');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('early_warnings', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
