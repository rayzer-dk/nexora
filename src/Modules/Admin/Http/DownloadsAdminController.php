<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Downloads\Application\DownloadCenterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DownloadsAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly DownloadCenterService $downloads)
    {
    }

    #[Route('/admin/content/downloads', name: 'admin_content_downloads', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storeId = $this->contexts->resolve($request)->storeId;
        $query = trim((string) $request->query->get('q', ''));
        $files = $this->downloads->adminList($storeId, $query);
        $editId = (int) $request->query->get('edit', 0);
        $edit = null;
        foreach ($files as $file) {
            if ((int) $file['id'] === $editId) {
                $edit = $file;
            }
        }

        return $this->render('@storefront/admin/content/downloads.html.twig', ['files' => $files, 'query' => $query, 'edit' => $edit]);
    }

    #[Route('/admin/content/downloads/upload', name: 'admin_content_downloads_upload', methods: ['POST'])]
    public function upload(Request $request): RedirectResponse
    {
        $this->guard($request);
        $context = $this->contexts->resolve($request);
        $file = $request->files->get('file');
        try {
            if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                throw new \InvalidArgumentException(CanonicalUiText::get('admin.downloads.error_upload'));
            }
            $this->downloads->upload($context->storeId, $file, (string) $request->request->get('title', ''), (string) $request->request->get('description', ''), (string) $request->request->get('group', ''), $request->request->getInt('sort', 100));
            $this->addFlash('success', CanonicalUiText::get('admin.downloads.saved'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_content_downloads');
    }

    #[Route('/admin/content/downloads/{id}/update', name: 'admin_content_downloads_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): RedirectResponse
    {
        $this->guard($request);
        try {
            $this->downloads->update($this->contexts->resolve($request)->storeId, $id, (string) $request->request->get('title', ''), (string) $request->request->get('description', ''), (string) $request->request->get('group', ''), $request->request->getInt('sort', 100));
            $this->addFlash('success', CanonicalUiText::get('admin.downloads.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_content_downloads');
    }

    #[Route('/admin/content/downloads/{id}/toggle', name: 'admin_content_downloads_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->downloads->toggle($this->contexts->resolve($request)->storeId, $id);

        return $this->redirectToRoute('admin_content_downloads');
    }

    #[Route('/admin/content/downloads/{id}/delete', name: 'admin_content_downloads_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->guard($request);
        $this->downloads->delete($this->contexts->resolve($request)->storeId, $id);

        return $this->redirectToRoute('admin_content_downloads');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_downloads', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
