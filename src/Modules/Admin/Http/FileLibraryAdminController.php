<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Media\Application\FileLibraryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** The libraries of product documents and digital downloads: one page to keep them in order, and the JSON the picker window of the product form talks to. */
final class FileLibraryAdminController extends AbstractController
{
    private const REQUIREMENT = ['library' => 'document|digital'];

    public function __construct(private readonly AdminContextResolver $contexts, private readonly FileLibraryService $files, private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    #[Route('/admin/files', name: 'admin_files', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->contexts->resolve($request);
        $library = $request->query->get('library') === FileLibraryService::DIGITAL ? FileLibraryService::DIGITAL : FileLibraryService::DOCUMENT;

        return $this->render('@storefront/admin/files/index.html.twig', ['library' => $library, 'token' => $this->csrf->getToken('admin_file_library')->getValue()]);
    }

    #[Route('/admin/files/{library}.json', name: 'admin_files_browse', methods: ['GET'], requirements: self::REQUIREMENT)]
    public function browse(Request $request, string $library): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        $folder = $request->query->getInt('folder', 0);

        return new JsonResponse($this->files->browse($context->storeId, $library, $folder > 0 ? $folder : null, (string) $request->query->get('q', '')));
    }

    #[Route('/admin/files/{library}/folder.json', name: 'admin_files_folder', methods: ['POST'], requirements: self::REQUIREMENT)]
    public function folder(Request $request, string $library): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_file_library', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'message' => CanonicalUiText::get('common.security.invalid_csrf')], 403);
        }
        try {
            $parent = $request->request->getInt('parent', 0);
            $id = $this->files->createFolder($context->storeId, $library, $parent > 0 ? $parent : null, (string) $request->request->get('name', ''));

            return new JsonResponse(['ok' => true, 'id' => $id]);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    #[Route('/admin/files/{library}/upload.json', name: 'admin_files_upload', methods: ['POST'], requirements: self::REQUIREMENT)]
    public function upload(Request $request, string $library): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_file_library', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false, 'message' => CanonicalUiText::get('common.security.invalid_csrf')], 403);
        }
        $folder = $request->request->getInt('folder', 0);
        $stored = [];
        $errors = [];
        $incoming = $request->files->get('files');
        foreach (is_array($incoming) ? $incoming : [$incoming] as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            try {
                $row = $this->files->upload($context->storeId, $library, $file, $folder > 0 ? $folder : null);
                $stored[] = ['id' => \Symfony\Component\Uid\Uuid::fromBinary((string) $row['public_id'])->toRfc4122(), 'title' => (string) $row['title'], 'filename' => (string) $row['original_filename']];
            } catch (\DomainException|\InvalidArgumentException $e) {
                $errors[] = $file->getClientOriginalName() . ': ' . $e->getMessage();
            } catch (\Throwable) {
                $errors[] = $file->getClientOriginalName() . ': ' . CanonicalUiText::get('admin.files.error.upload');
            }
        }

        return new JsonResponse(['ok' => $errors === [], 'stored' => $stored, 'errors' => $errors], $errors === [] ? 200 : 422);
    }
}
