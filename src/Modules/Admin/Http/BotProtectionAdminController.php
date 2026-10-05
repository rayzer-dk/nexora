<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Security\Bots\BotProtection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Block harmful and unwanted crawlers: built-in lists, the shop's own words and addresses, a robots.txt for AI crawlers. */
final class BotProtectionAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly BotProtection $bots)
    {
    }

    #[Route('/admin/system/bots', name: 'admin_system_bots', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_bots', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            if ($request->request->get('action') === 'test') {
                $verdict = $this->bots->test((string) $request->request->get('test_ua', ''), (string) $request->request->get('test_ip', ''));
                $this->addFlash($verdict === null ? 'success' : 'warning', $verdict === null ? CanonicalUiText::get('admin.bots.test_pass') : CanonicalUiText::get('admin.bots.test_blocked', ['reason' => CanonicalUiText::get(match ($verdict) {
                    'scanners' => 'admin.bots.reason_scanners',
                    'scrapers' => 'admin.bots.reason_scrapers',
                    'seo' => 'admin.bots.reason_seo',
                    'ai' => 'admin.bots.reason_ai',
                    'ip' => 'admin.bots.reason_ip',
                    'empty_ua' => 'admin.bots.reason_empty_ua',
                    default => 'admin.bots.reason_custom',
                })]));

                return $this->redirectToRoute('admin_system_bots');
            }
            $this->bots->save($request->request->all());
            $this->addFlash('success', CanonicalUiText::get('admin.bots.saved'));

            return $this->redirectToRoute('admin_system_bots');
        }
        $config = $this->bots->config();

        return $this->render('@storefront/admin/system/bots.html.twig', [
            'config' => $config,
            'categories' => BotProtection::CATEGORIES,
            'stats' => $this->bots->stats(),
            'my_ip' => (string) $request->getClientIp(),
            'my_ua' => (string) $request->headers->get('User-Agent', ''),
            'ai_robots' => BotProtection::ROBOTS_AI,
        ]);
    }
}
