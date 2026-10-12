<?php

declare(strict_types=1);

namespace Commerce\Modules\Fop\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Fop\Application\FopService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → FOP: the income of the year against the limit of the single-tax group, and the book of income for the accountant. */
final class FopAdminController extends AbstractController
{
    public function __construct(private readonly FopService $fop, private readonly AdminContextResolver $contexts)
    {
    }

    #[Route('/admin/finance/fop', name: 'admin_fop', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $year = (int) gmdate('Y');

        return $this->render('@storefront/admin/fop/index.html.twig', [
            'settings' => $this->fop->settings($context->storeId),
            'summary' => $this->fop->summary($context->storeId),
            'limit_default' => FopService::limitMinor(['tax_group' => $this->fop->settings($context->storeId)['tax_group'], 'min_wage_minor' => $this->fop->settings($context->storeId)['min_wage_minor'], 'limit_override_minor' => null]),
            'year_start' => $year . '-01-01',
            'today' => gmdate('Y-m-d'),
        ]);
    }

    #[Route('/admin/finance/fop/save', name: 'admin_fop_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_fop', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        try {
            $this->fop->save(
                $context->storeId, $request->request->getBoolean('enabled'), $request->request->getInt('tax_group', 3),
                (string) $request->request->get('min_wage', ''), (string) $request->request->get('limit_override', ''), $request->request->getInt('warn_percent', 80),
            );
            $this->addFlash('success', CanonicalUiText::get('admin.fop.saved'));
        } catch (\DomainException) {
            $this->addFlash('error', CanonicalUiText::get('admin.fop.bad_number'));
        }

        return $this->redirectToRoute('admin_fop');
    }

    #[Route('/admin/finance/fop/income-book.csv', name: 'admin_fop_income_book', methods: ['GET'])]
    public function incomeBook(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $utc = new \DateTimeZone('UTC');
        $parse = static function (string $v, string $fallback) use ($utc): \DateTimeImmutable {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, $utc);

            return $d !== false ? $d : new \DateTimeImmutable($fallback, $utc);
        };
        $from = $parse((string) $request->query->get('from', ''), gmdate('Y') . '-01-01');
        $to = $parse((string) $request->query->get('to', ''), 'today');
        if ($to < $from || $from->diff($to)->days > 800) {
            throw $this->createNotFoundException();
        }
        $rows = $this->fop->incomeBook($context->storeId, $from, $to);
        $name = 'income-book-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv';
        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // Excel opens UTF-8 with the BOM
            fputcsv($out, [CanonicalUiText::get('admin.fop.csv_no'), CanonicalUiText::get('admin.fop.csv_date'), CanonicalUiText::get('admin.fop.csv_document'), CanonicalUiText::get('admin.fop.csv_method'), CanonicalUiText::get('admin.fop.csv_amount'), CanonicalUiText::get('admin.fop.csv_note')], ';', '"', '');
            $total = 0;
            foreach ($rows as $i => $r) {
                $total += $r['amount_minor'];
                fputcsv($out, [$i + 1, $r['date'], $r['document'], $r['method'], number_format($r['amount_minor'] / 100, 2, '.', ''), $r['note']], ';', '"', '');
            }
            fputcsv($out, ['', '', '', CanonicalUiText::get('admin.fop.csv_total'), number_format($total / 100, 2, '.', ''), ''], ';', '"', '');
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '"');

        return $response;
    }
}
