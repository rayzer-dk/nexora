<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Storefront\Application\LandingContent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LandingAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly LandingContent $landing)
    {
    }

    #[Route('/admin/appearance/landing', name: 'admin_appearance_landing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        $locale = $this->locale((string) $request->query->get('locale', ''));

        return $this->render('@storefront/admin/appearance/landing.html.twig', [
            'locale' => $locale,
            'locales' => LandingContent::LOCALES,
            'content' => $this->landing->forLocale($locale),
            'icons' => LandingContent::FEATURE_ICONS,
        ]);
    }

    #[Route('/admin/appearance/landing/save', name: 'admin_appearance_landing_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_landing', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $locale = $this->locale((string) $request->request->get('locale', ''));
        if ($request->request->has('reset')) {
            $this->landing->reset($locale);
            $this->addFlash('success', CanonicalUiText::get('admin.landing.reset_done'));
        } else {
            $this->landing->save($locale, $request->request->all());
            $this->addFlash('success', CanonicalUiText::get('admin.landing.saved'));
        }

        return $this->redirectToRoute('admin_appearance_landing', ['locale' => $locale]);
    }

    private function locale(string $value): string
    {
        return in_array($value, LandingContent::LOCALES, true) ? $value : 'uk-UA';
    }
}
