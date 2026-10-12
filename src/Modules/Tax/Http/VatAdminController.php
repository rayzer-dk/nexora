<?php

declare(strict_types=1);

namespace Commerce\Modules\Tax\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Tax\Application\VatReportService;
use Commerce\Modules\Tax\Application\ViesClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → VAT / OSS: VAT by the country of the buyer per quarter, and a check of an EU VAT number in VIES. */
final class VatAdminController extends AbstractController
{
    public function __construct(private readonly VatReportService $report, private readonly ViesClient $vies, private readonly AdminContextResolver $contexts, private readonly \Doctrine\DBAL\Connection $db)
    {
    }

    #[Route('/admin/finance/vat/settings', name: 'admin_vat_settings', methods: ['POST'])]
    public function settings(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('admin_vat_settings', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $this->db->executeStatement('INSERT INTO mc_vat_settings (store_id,reverse_charge,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reverse_charge=VALUES(reverse_charge),updated_at=VALUES(updated_at)', [$context->storeId, $request->request->getBoolean('reverse_charge') ? 1 : 0, gmdate('Y-m-d H:i:s.u')]);
        $this->addFlash('success', CanonicalUiText::get('admin.fop.saved'));

        return $this->redirectToRoute('admin_vat');
    }

    #[Route('/admin/finance/vat', name: 'admin_vat', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $year = max(2000, min(2100, $request->query->getInt('year', (int) $now->format('Y'))));
        $quarter = max(1, min(4, $request->query->getInt('quarter', (int) ceil((int) $now->format('n') / 3))));
        $check = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_vat_check', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            try {
                $check = $this->vies->check((string) $request->request->get('country', ''), (string) $request->request->get('number', ''))
                    + ['country' => strtoupper((string) $request->request->get('country', '')), 'number' => (string) $request->request->get('number', '')];
            } catch (\Throwable) {
                $check = ['valid' => false, 'name' => '', 'address' => '', 'state' => 'SERVICE_UNAVAILABLE', 'country' => strtoupper((string) $request->request->get('country', '')), 'number' => (string) $request->request->get('number', '')];
            }
        }

        return $this->render('@storefront/admin/vat/index.html.twig', [
            'rows' => $this->report->byCountry($context->storeId, $year, $quarter), 'year' => $year, 'quarter' => $quarter,
            'reverse_charge' => (bool) $this->db->fetchOne('SELECT reverse_charge FROM mc_vat_settings WHERE store_id=?', [$context->storeId]),
            'countries' => ViesClient::COUNTRIES, 'check' => $check, 'years' => range((int) $now->format('Y'), (int) $now->format('Y') - 4),
        ]);
    }

    #[Route('/admin/finance/vat/report.csv', name: 'admin_vat_report', methods: ['GET'])]
    public function csv(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $year = max(2000, min(2100, $request->query->getInt('year', (int) gmdate('Y'))));
        $quarter = max(1, min(4, $request->query->getInt('quarter', 1)));
        $rows = $this->report->byCountry($context->storeId, $year, $quarter);
        $response = new StreamedResponse(static function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [CanonicalUiText::get('admin.vat.country'), CanonicalUiText::get('admin.vat.orders'), CanonicalUiText::get('admin.vat.net'), CanonicalUiText::get('admin.vat.vat'), CanonicalUiText::get('admin.vat.gross'), CanonicalUiText::get('admin.vat.refunded'), CanonicalUiText::get('admin.vat.currency')], ';', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, [$r['country'], $r['orders'], number_format($r['net_minor'] / 100, 2, '.', ''), number_format($r['vat_minor'] / 100, 2, '.', ''), number_format($r['gross_minor'] / 100, 2, '.', ''), number_format($r['refunded_minor'] / 100, 2, '.', ''), $r['currency']], ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="vat-by-country-' . $year . '-Q' . $quarter . '.csv"');

        return $response;
    }
}
