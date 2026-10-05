<?php

declare(strict_types=1);

namespace Commerce\Modules\Seo\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Seo\Application\CustomRedirectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → System → SEO redirects → own redirects and the list of pages visitors could not find. */
final class CustomRedirectAdminController extends AbstractController
{
    public function __construct(private readonly CustomRedirectService $redirects)
    {
    }

    #[Route('/admin/system/seo-custom-redirects', name: 'admin_system_seo_custom_redirects', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query->get('search', '')), 0, 190);

        return $this->render('@storefront/admin/system/custom_redirects.html.twig', [
            'rows' => $this->redirects->all($search),
            'not_found' => array_map(fn (array $n): array => $n + ['suggestion' => $this->redirects->suggest((string) $n['path'])], $this->redirects->topNotFound(50)),
            'search' => $search,
            'prefill' => CustomRedirectService::normalizePath((string) $request->query->get('source', '')) !== '/' ? (string) $request->query->get('source', '') : '',
        ]);
    }

    #[Route('/admin/system/seo-custom-redirects/save', name: 'admin_system_seo_custom_redirect_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->guard($request);
        try {
            $this->redirects->add((string) $request->request->get('source', ''), (string) $request->request->get('target', ''), $request->request->getInt('status_code', 301));
            $this->addFlash('success', CanonicalUiText::get('admin.redirects.saved'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_system_seo_custom_redirects');
    }

    #[Route('/admin/system/seo-custom-redirects/import', name: 'admin_system_seo_custom_redirect_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $this->guard($request);
        $text = (string) $request->request->get('lines', '');
        $file = $request->files->get('file');
        if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->isValid() && $file->getSize() <= 2 * 1024 * 1024) {
            $text .= "\n" . (string) file_get_contents($file->getPathname());
        }
        $r = $this->redirects->import($text);
        $this->addFlash('success', CanonicalUiText::get('admin.redirects.imported', ['added' => (string) $r['added'], 'skipped' => (string) $r['skipped']]));

        return $this->redirectToRoute('admin_system_seo_custom_redirects');
    }

    #[Route('/admin/system/seo-custom-redirects/{id}/toggle', name: 'admin_system_seo_custom_redirect_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): Response
    {
        $this->guard($request);
        $this->redirects->setEnabled($id, $request->request->getBoolean('enabled'));

        return $this->redirectToRoute('admin_system_seo_custom_redirects');
    }

    #[Route('/admin/system/seo-custom-redirects/{id}/delete', name: 'admin_system_seo_custom_redirect_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $this->guard($request);
        $this->redirects->delete($id);
        $this->addFlash('success', CanonicalUiText::get('admin.redirects.deleted'));

        return $this->redirectToRoute('admin_system_seo_custom_redirects');
    }

    #[Route('/admin/system/seo-custom-redirects/clear-404', name: 'admin_system_seo_custom_redirect_clear_404', methods: ['POST'])]
    public function clearNotFound(Request $request): Response
    {
        $this->guard($request);
        $this->redirects->clearNotFound();
        $this->addFlash('success', CanonicalUiText::get('admin.redirects.cleared'));

        return $this->redirectToRoute('admin_system_seo_custom_redirects');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('seo_custom_redirects', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
