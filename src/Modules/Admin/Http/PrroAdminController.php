<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Prro\Application\CheckboxException;
use Commerce\Modules\Prro\Application\FiscalizationService;
use Commerce\Modules\Prro\Application\PrroSettings;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Cash register (PRRO): account settings, a connection check, and a receipt for one order on demand. */
final class PrroAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly PrroSettings $settings, private readonly FiscalizationService $fiscal, private readonly Connection $db, private readonly LoggerInterface $logger, private readonly \Commerce\Modules\Notification\Application\NotificationOutbox $notifications)
    {
    }

    #[Route('/admin/system/prro', name: 'admin_system_prro', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $this->contexts->resolve($request);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('prro_settings', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $this->settings->save($request->request->all());
                if ($request->request->get('action') === 'test') {
                    $this->fiscal->testConnection();
                    $this->addFlash('success', CanonicalUiText::get('admin.prro.test_ok'));
                } else {
                    $this->addFlash('success', CanonicalUiText::get('admin.prro.saved'));
                }
            } catch (CheckboxException $e) {
                $this->addFlash('error', CanonicalUiText::get('admin.prro.provider_error', ['message' => $e->getMessage()]));
            } catch (\DomainException $e) {
                $this->addFlash('error', $this->errorText($e->getMessage()));
            } catch (\Throwable $e) {
                $this->logger->error('PRRO settings action failed', ['exception' => $e]);
                $this->addFlash('error', CanonicalUiText::get('admin.prro.error.unknown'));
            }

            return $this->redirectToRoute('admin_system_prro');
        }
        $cfg = $this->settings->get();
        unset($cfg['password'], $cfg['license']);
        $status = (string) $request->query->get('status', '');
        $status = in_array($status, ['done', 'error', 'pending'], true) ? $status : '';
        $term = mb_substr(trim((string) $request->query->get('q', '')), 0, 60);
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query->get('from', '')) === 1 ? (string) $request->query->get('from') : '';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query->get('to', '')) === 1 ? (string) $request->query->get('to') : '';
        $where = ['1=1'];
        $params = [];
        if ($status === 'pending') {
            $where[] = "f.status NOT IN ('done','error')";
        } elseif ($status !== '') {
            $where[] = 'f.status=?';
            $params[] = $status;
        }
        if ($term !== '') {
            $where[] = '(o.order_number LIKE ? OR f.fiscal_code LIKE ?)';
            $params[] = $params[] = '%' . addcslashes($term, '%_\\') . '%';
        }
        if ($from !== '') {
            $where[] = 'f.updated_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'f.updated_at <= ?';
            $params[] = $to . ' 23:59:59.999999';
        }
        $condition = implode(' AND ', $where);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_fiscal_receipt f JOIN mc_sales_order o ON o.id=f.order_id WHERE ' . $condition, $params);
        $page = max(1, (int) $request->query->get('page', 1));
        $recent = $this->db->fetchAllAssociative('SELECT f.id,f.kind,f.status,f.fiscal_code,f.receipt_url,f.error_text,f.updated_at,o.order_number,o.customer_email,HEX(o.public_id) AS public_hex FROM mc_fiscal_receipt f JOIN mc_sales_order o ON o.id=f.order_id WHERE ' . $condition . ' ORDER BY f.id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25), $params);
        foreach ($recent as &$r) {
            $r['order_public_id'] = Uuid::fromBinary((string) hex2bin((string) $r['public_hex']))->toRfc4122();
        }
        unset($r);

        return $this->render('@storefront/admin/system/prro.html.twig', ['cfg' => $cfg, 'has_secrets' => $this->settings->get()['configured'], 'recent' => $recent, 'total' => $total, 'page' => $page, 'filter' => ['status' => $status, 'q' => $term, 'from' => $from, 'to' => $to]]);
    }

    /** Sends the link of a finished receipt to the customer of the order (the cash register may already have e-mailed it; this is the manual resend). */
    #[Route('/admin/system/prro/receipt/{id}/send', name: 'admin_system_prro_receipt_send', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function send(int $id, Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('prro_receipt_send', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        // Only a receipt of an order of the store being managed: another store's receipt reads as "not found".
        $row = $this->db->fetchAssociative("SELECT f.id,f.receipt_url,o.order_number,o.customer_email FROM mc_fiscal_receipt f JOIN mc_sales_order o ON o.id=f.order_id WHERE f.id=? AND f.status='done' AND o.store_id=?", [$id, $context->storeId]);
        $email = is_array($row) ? trim((string) $row['customer_email']) : '';
        if (!is_array($row) || (string) $row['receipt_url'] === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('error', CanonicalUiText::get('admin.prro.send_no_email'));

            return $this->redirect($this->back($request));
        }
        $subject = CanonicalUiText::get('admin.prro.send_subject', ['order' => (string) $row['order_number']]);
        $text = CanonicalUiText::get('admin.prro.send_text', ['order' => (string) $row['order_number'], 'url' => (string) $row['receipt_url']]);
        $this->notifications->enqueue(\Commerce\Modules\Notification\Domain\NotificationChannel::Email, new \Commerce\Modules\Notification\Domain\NotificationMessage('prro_receipt', $subject, $text, [], 'generic'), $email, null, 'prro-receipt:' . $id . ':' . bin2hex(random_bytes(4)));
        $this->addFlash('success', CanonicalUiText::get('admin.prro.send_ok', ['email' => $email]));

        return $this->redirect($this->back($request));
    }

    private function back(Request $request): string
    {
        $back = (string) $request->request->get('back', '');

        return str_starts_with($back, '/admin/system/prro') && !str_contains($back, "\n") ? $back : '/admin/system/prro';
    }

    #[Route('/admin/orders/{publicId}/fiscalize', name: 'admin_order_fiscalize', methods: ['POST'])]
    public function fiscalize(string $publicId, Request $request): Response
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('prro_order_' . $publicId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $binary = Uuid::fromString($publicId)->toBinary();
        } catch (\Throwable) {
            throw $this->createNotFoundException();
        }
        $orderId = $this->db->fetchOne('SELECT id FROM mc_sales_order WHERE public_id=?', [$binary]);
        if ($orderId === false) {
            throw $this->createNotFoundException();
        }
        try {
            $this->fiscal->fiscalize((int) $orderId, true);
            $this->addFlash('success', CanonicalUiText::get('admin.prro.receipt_done'));
        } catch (CheckboxException $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.prro.provider_error', ['message' => $e->getMessage()]));
        } catch (\DomainException $e) {
            $this->addFlash('error', $this->errorText($e->getMessage()));
        } catch (\Throwable $e) {
            $this->logger->error('PRRO fiscalization failed', ['exception' => $e]);
            $this->addFlash('error', CanonicalUiText::get('admin.prro.error.unknown'));
        }

        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    #[Route('/admin/orders/{publicId}/fiscalize-refund/{refundId}', name: 'admin_order_fiscalize_refund', requirements: ['refundId' => '\\d+'], methods: ['POST'])]
    public function fiscalizeRefund(string $publicId, int $refundId, Request $request): Response
    {
        $this->contexts->resolve($request);
        if (!$this->isCsrfTokenValid('prro_refund_' . $refundId, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->fiscal->fiscalizeRefund($refundId);
            $this->addFlash('success', CanonicalUiText::get('admin.prro.return_done'));
        } catch (CheckboxException $e) {
            $this->addFlash('error', CanonicalUiText::get('admin.prro.provider_error', ['message' => $e->getMessage()]));
        } catch (\DomainException $e) {
            $this->addFlash('error', $this->errorText($e->getMessage()));
        } catch (\Throwable $e) {
            $this->logger->error('PRRO fiscalization failed', ['exception' => $e]);
            $this->addFlash('error', CanonicalUiText::get('admin.prro.error.unknown'));
        }

        return $this->redirectToRoute('admin_order_view', ['publicId' => $publicId]);
    }

    private function errorText(string $code): string
    {
        return match ($code) {
            'prro_not_configured' => CanonicalUiText::get('admin.prro.error.prro_not_configured'),
            'order_not_found' => CanonicalUiText::get('admin.prro.error.order_not_found'),
            'payment_method_skipped' => CanonicalUiText::get('admin.prro.error.payment_method_skipped'),
            'order_not_paid' => CanonicalUiText::get('admin.prro.error.order_not_paid'),
            'totals_mismatch' => CanonicalUiText::get('admin.prro.error.totals_mismatch'),
            'refund_not_ready' => CanonicalUiText::get('admin.prro.error.refund_not_ready'),
            'sale_receipt_missing' => CanonicalUiText::get('admin.prro.error.sale_receipt_missing'),
            default => CanonicalUiText::get('admin.prro.error.unknown'),
        };
    }
}
