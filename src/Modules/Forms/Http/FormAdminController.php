<?php

declare(strict_types=1);

namespace Commerce\Modules\Forms\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Forms\Application\FormService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Admin side of the form builder: forms, their fields, the answers and a CSV export. */
final class FormAdminController extends AbstractController
{
    private const SLOTS = 12;

    public function __construct(private readonly AdminContextResolver $contexts, private readonly FormService $forms, private readonly Connection $db)
    {
    }

    #[Route('/admin/content/forms', name: 'admin_content_forms', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('@storefront/admin/content/forms.html.twig', ['forms' => $this->forms->all($this->storeId($request))]);
    }

    #[Route('/admin/content/forms/new', name: 'admin_content_form_new', methods: ['GET'])]
    public function create(Request $request): Response
    {
        return $this->editor($request, ['id' => null, 'name' => '', 'slug' => '', 'locale' => '', 'status' => 'draft', 'intro' => '', 'submit_label' => '', 'success_message' => '', 'notify_email' => '', 'fields' => []]);
    }

    #[Route('/admin/content/forms/{id}', name: 'admin_content_form_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $form = $this->forms->find($this->storeId($request), $id);
        if ($form === null) {
            throw $this->createNotFoundException();
        }

        return $this->editor($request, $form);
    }

    #[Route('/admin/content/forms/save', name: 'admin_content_form_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $this->guard($request);
        $id = (int) $request->request->get('id', 0);
        try {
            $saved = $this->forms->save($this->storeId($request), $id > 0 ? $id : null, $request->request->all());
            $this->addFlash('success', CanonicalUiText::get('admin.forms.saved'));

            return $this->redirectToRoute('admin_content_form_edit', ['id' => $saved]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.forms.error.' . $e->getMessage()));

            return $id > 0 ? $this->redirectToRoute('admin_content_form_edit', ['id' => $id]) : $this->redirectToRoute('admin_content_form_new');
        }
    }

    #[Route('/admin/content/forms/{id}/delete', name: 'admin_content_form_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): RedirectResponse
    {
        $this->guard($request);
        $this->forms->delete($this->storeId($request), $id);
        $this->addFlash('success', CanonicalUiText::get('admin.forms.deleted'));

        return $this->redirectToRoute('admin_content_forms');
    }

    #[Route('/admin/content/forms/{id}/submissions', name: 'admin_content_form_submissions', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function submissions(Request $request, int $id): Response
    {
        $storeId = $this->storeId($request);
        $form = $this->forms->find($storeId, $id);
        if ($form === null) {
            throw $this->createNotFoundException();
        }
        // Opening the list marks fresh answers as read; "handled" stays a deliberate action.
        $this->db->executeStatement("UPDATE mc_form_submission SET status='read' WHERE form_id=? AND store_id=? AND status='new'", [$id, $storeId]);

        return $this->render('@storefront/admin/content/form_submissions.html.twig', ['form' => $form, 'submissions' => $this->forms->submissions($storeId, $id)]);
    }

    #[Route('/admin/content/forms/{id}/export', name: 'admin_content_form_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function export(Request $request, int $id): Response
    {
        $storeId = $this->storeId($request);
        $form = $this->forms->find($storeId, $id);
        if ($form === null) {
            throw $this->createNotFoundException();
        }
        $rows = $this->forms->csvRows($storeId, $form);
        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '\\');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="form-' . preg_replace('/[^a-z0-9-]/', '', (string) $form['slug']) . '.csv"');

        return $response;
    }

    #[Route('/admin/content/forms/{id}/submissions/{submission}/status', name: 'admin_content_form_submission_status', methods: ['POST'], requirements: ['id' => '\d+', 'submission' => '\d+'])]
    public function status(Request $request, int $id, int $submission): RedirectResponse
    {
        $this->guard($request);
        $this->forms->setSubmissionStatus($this->storeId($request), $submission, (string) $request->request->get('status', ''));

        return $this->redirectToRoute('admin_content_form_submissions', ['id' => $id]);
    }

    #[Route('/admin/content/forms/{id}/submissions/{submission}/delete', name: 'admin_content_form_submission_delete', methods: ['POST'], requirements: ['id' => '\d+', 'submission' => '\d+'])]
    public function deleteSubmission(Request $request, int $id, int $submission): RedirectResponse
    {
        $this->guard($request);
        $this->forms->deleteSubmission($this->storeId($request), $submission);
        $this->addFlash('success', CanonicalUiText::get('admin.forms.deleted'));

        return $this->redirectToRoute('admin_content_form_submissions', ['id' => $id]);
    }

    /** @param array<string,mixed> $form */
    private function editor(Request $request, array $form): Response
    {
        $storeId = $this->storeId($request);
        $locales = $this->db->fetchAllAssociative('SELECT l.code,l.native_name FROM mc_store_locale sl JOIN mc_locale l ON l.code=sl.locale_code WHERE sl.store_id=? AND sl.enabled=1 ORDER BY sl.sort_order,l.code', [$storeId]);

        return $this->render('@storefront/admin/content/form_edit.html.twig', [
            'form' => $form, 'locales' => $locales, 'types' => FormService::TYPES,
            'slots' => max(self::SLOTS, count((array) ($form['fields'] ?? [])) + 3),
        ]);
    }

    private function storeId(Request $request): int
    {
        return $this->contexts->resolve($request)->storeId;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_forms', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
    }
}
