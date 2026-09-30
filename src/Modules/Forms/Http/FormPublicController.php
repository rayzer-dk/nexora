<?php

declare(strict_types=1);

namespace Commerce\Modules\Forms\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Forms\Application\FormService;
use Commerce\Modules\Security\Spam\PublicFormProtection;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Public pages of the form builder: show a form, accept an answer. */
final class FormPublicController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly FormService $forms,
        private readonly PublicFormProtection $protection,
    ) {
    }

    #[Route('/forms/{slug}', name: 'storefront_form', methods: ['GET'], requirements: ['slug' => '[a-z0-9][a-z0-9-]*'], priority: 900)]
    public function show(Request $request, string $slug): Response
    {
        $context = $this->contexts->resolve($request);
        $form = $this->forms->findPublic($context->storeId, $slug);
        if ($form === null) {
            throw $this->createNotFoundException();
        }
        $canonical = $request->getSchemeAndHttpHost() . '/forms/' . $slug;

        return $this->render('@storefront/forms/show.html.twig', [
            'form' => $form,
            'page_title' => (string) $form['name'],
            'seo_head' => ['title' => (string) $form['name'], 'description' => (string) ($form['intro'] ?? ''), 'canonical' => $canonical, 'robots' => 'noindex,follow', 'hreflang' => [$context->locale => $canonical, 'x-default' => $canonical]],
        ]);
    }

    #[Route('/forms/{slug}/submit', name: 'storefront_form_submit', methods: ['POST'], requirements: ['slug' => '[a-z0-9][a-z0-9-]*'], priority: 950)]
    public function submit(Request $request, string $slug): Response
    {
        if (!$this->isCsrfTokenValid('form_' . $slug, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $context = $this->contexts->resolve($request);
        $form = $this->forms->findPublic($context->storeId, $slug);
        if ($form === null) {
            throw $this->createNotFoundException();
        }
        $ajax = $request->headers->get('X-Requested-With') === 'XMLHttpRequest' || str_contains((string) $request->headers->get('Accept'), 'application/json');
        if (!$this->protection->allow($request, 'form', 'custom_form')) {
            $message = CanonicalUiText::get('captcha_failed_or_spam');

            return $this->respond($request, $slug, false, $message, $ajax, 429);
        }
        try {
            $this->forms->submit($context->storeId, $form, $request->request->all(), $request->getClientIp() ?? '', $context->locale);
            $ok = true;
            $message = (string) ($form['success_message'] ?? '') !== '' ? (string) $form['success_message'] : CanonicalUiText::get('forms.success.default');
        } catch (\DomainException $e) {
            $ok = false;
            $message = $e->getMessage();
        }

        return $this->respond($request, $slug, $ok, $message, $ajax, $ok ? 200 : 422);
    }

    private function respond(Request $request, string $slug, bool $ok, string $message, bool $ajax, int $status): Response
    {
        if ($ajax) {
            return new JsonResponse(['ok' => $ok, 'message' => $message], $status);
        }
        $this->addFlash($ok ? 'success' : 'error', $message);

        return new RedirectResponse('/forms/' . $slug);
    }
}
