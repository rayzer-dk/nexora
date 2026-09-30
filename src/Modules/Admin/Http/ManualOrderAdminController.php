<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Order\Application\ManualOrderService;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ManualOrderAdminController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly ManualOrderService $orders,
        private readonly PaymentProviderRegistry $payments,
        private readonly Connection $db,
    ) {
    }

    #[Route('/admin/orders/manual', name: 'admin_orders_manual', methods: ['GET', 'POST'], priority: 20)]
    public function __invoke(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $form = $this->prefill($context->storeId, $request->query->getInt('inquiry'));
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_manual_order', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
            }
            $form = $this->read($request);
            try {
                $user = $this->getUser();
                $result = $this->orders->create($context->storeId, $context->marketId, $context->locale, $context->currency, $user instanceof AdminUser ? $user->displayName : 'admin', $form);
                $this->addFlash('success', CanonicalUiText::get('admin.manual_order.created', ['number' => $result['order_number']]));

                return $this->redirectToRoute('admin_order_view', ['publicId' => $result['public_id']]);
            } catch (\DomainException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('@storefront/admin/orders/manual.html.twig', [
            'form' => $form,
            'methods' => $this->payments->enabledMethods(),
            'currency' => $context->currency,
        ]);
    }

    /** @return array<string,mixed> */
    private function read(Request $request): array
    {
        $lines = [];
        $skus = $request->request->all('sku');
        $qty = $request->request->all('qty');
        $price = $request->request->all('price');
        foreach (array_slice(array_keys($skus), 0, 30) as $i) {
            $lines[] = ['sku' => (string) ($skus[$i] ?? ''), 'quantity' => (string) ($qty[$i] ?? '1'), 'price' => (string) ($price[$i] ?? '')];
        }

        return [
            'name' => trim((string) $request->request->get('name', '')),
            'phone' => trim((string) $request->request->get('phone', '')),
            'email' => trim((string) $request->request->get('email', '')),
            'comment' => trim((string) $request->request->get('comment', '')),
            'payment_method' => trim((string) $request->request->get('payment_method', 'cash_on_delivery')),
            'delivery' => trim((string) $request->request->get('delivery', '')),
            'coupon' => trim((string) $request->request->get('coupon', '')),
            'lines' => $lines,
            'inquiry_id' => $request->request->getInt('inquiry_id'),
        ];
    }

    /** @return array<string,mixed> */
    private function prefill(int $storeId, int $inquiryId): array
    {
        $blank = ['name' => '', 'phone' => '', 'email' => '', 'comment' => '', 'payment_method' => 'cash_on_delivery', 'delivery' => '', 'coupon' => '', 'lines' => [['sku' => '', 'quantity' => '1', 'price' => '']], 'inquiry_id' => 0];
        if ($inquiryId <= 0) {
            return $blank;
        }
        $row = $this->db->fetchAssociative("SELECT i.id,i.customer_name,i.email,i.phone,i.message,v.sku product_sku FROM mc_customer_inquiry i LEFT JOIN mc_product_variant v ON v.product_id=i.product_id AND v.sort_order=0 WHERE i.id=? AND i.store_id=? AND i.inquiry_type='quick_order'", [$inquiryId, $storeId]);
        if (!is_array($row)) {
            return $blank;
        }
        $qty = '1';
        $sku = (string) ($row['product_sku'] ?? '');
        if (preg_match('/^×\s*([0-9.,]+)(?:\s*·\s*(\S+))?/u', (string) $row['message'], $m) === 1) {
            $qty = str_replace(',', '.', $m[1]);
            $sku = ($m[2] ?? '') !== '' ? $m[2] : $sku;
        }

        return ['name' => (string) $row['customer_name'], 'phone' => (string) ($row['phone'] ?? ''), 'email' => (string) ($row['email'] ?? ''), 'comment' => '', 'payment_method' => 'cash_on_delivery', 'delivery' => '', 'coupon' => '', 'lines' => [['sku' => $sku, 'quantity' => $qty, 'price' => '']], 'inquiry_id' => (int) $row['id']];
    }
}
