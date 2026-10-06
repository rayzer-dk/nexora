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
    public function __construct(private readonly AdminContextResolver $contexts, private readonly PrroSettings $settings, private readonly FiscalizationService $fiscal, private readonly Connection $db, private readonly LoggerInterface $logger)
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
        $recent = $this->db->fetchAllAssociative("SELECT f.status,f.fiscal_code,f.receipt_url,f.error_text,f.updated_at,o.order_number,HEX(o.public_id) AS public_hex FROM mc_fiscal_receipt f JOIN mc_sales_order o ON o.id=f.order_id ORDER BY f.id DESC LIMIT 15");
        foreach ($recent as &$r) {
            $r['order_public_id'] = Uuid::fromBinary((string) hex2bin((string) $r['public_hex']))->toRfc4122();
        }
        unset($r);

        return $this->render('@storefront/admin/system/prro.html.twig', ['cfg' => $cfg, 'has_secrets' => $this->settings->get()['configured'], 'recent' => $recent]);
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
