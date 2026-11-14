<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\Payment\Provider\GooglePay\GooglePayPaymentProvider;
use Commerce\Modules\Security\Http\CspExtra;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** The page with the Google Pay button for an order placed with the "Google Pay" method, and the endpoint that takes the money. */
final class GooglePayController extends AbstractController
{
    private const OPEN_STATUSES = ['pending', 'awaiting_payment', 'processing'];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentLifecycleService $lifecycle,
        private readonly string $publicBaseUrl,
    ) {
    }

    #[Route('/payment/google-pay/{order}', name: 'storefront_google_pay', methods: ['GET'], priority: 100)]
    public function page(string $order, Request $request): Response
    {
        $row = $this->payableOrder($order);
        if ($row === null || !in_array((string) $row['payment_status'], self::OPEN_STATUSES, true)) {
            return $this->redirectToRoute('storefront_payment_return', ['order' => $order]);
        }
        $provider = $this->provider();
        CspExtra::merge($request, ['script' => ['https://pay.google.com'], 'connect' => ['https://pay.google.com', 'https://google.com'], 'frame' => ['https://pay.google.com']]);
        $minor = (int) $row['total_minor'];

        return $this->render('@storefront/checkout/google_pay.html.twig', [
            'page_title' => CanonicalUiText::get('payment.method.google_pay'),
            'order_public_id' => $order,
            'order_number' => (string) $row['order_number'],
            'amount_label' => number_format($minor / 100, 2, ',', ' ') . ' ' . $row['currency'],
            'config' => $provider->clientConfig() + [
                'amount' => number_format($minor / 100, 2, '.', ''), 'currency' => (string) $row['currency'],
                'country' => strtoupper((string) $row['default_country']), 'merchant_name' => (string) $row['store_name'],
                'charge_url' => $this->generateUrl('storefront_google_pay_charge', ['order' => $order]),
            ],
            'seo_head' => ['robots' => 'noindex,nofollow'],
        ]);
    }

    #[Route('/payment/google-pay/{order}/charge', name: 'storefront_google_pay_charge', methods: ['POST'], priority: 110)]
    public function charge(string $order, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('google_pay_' . $order, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['status' => 'failed', 'message' => CanonicalUiText::get('common.security.invalid_csrf')], 403);
        }
        $row = $this->payableOrder($order);
        if ($row === null || !in_array((string) $row['payment_status'], self::OPEN_STATUSES, true)) {
            return new JsonResponse(['status' => 'failed', 'message' => CanonicalUiText::get('payment.google_pay.closed')], 409);
        }
        $body = json_decode($request->getContent(), true);
        $tokenId = GooglePayPaymentProvider::stripeTokenId(is_array($body) ? (string) ($body['token'] ?? '') : '');
        if ($tokenId === null) {
            return new JsonResponse(['status' => 'failed', 'message' => CanonicalUiText::get('payment.google_pay.bad_token')], 400);
        }
        $returnUrl = rtrim($this->publicBaseUrl, '/') . '/payment/return/' . $order;
        // Every attempt carries its own token, so a double click would charge twice: one attempt per order at a time.
        $lock = 'gpay:' . $order;
        if ((int) $this->db->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return new JsonResponse(['status' => 'failed', 'message' => CanonicalUiText::get('payment.google_pay.closed')], 409);
        }
        try {
            $row = $this->payableOrder($order);
            if ($row === null || !in_array((string) $row['payment_status'], self::OPEN_STATUSES, true)) {
                return new JsonResponse(['status' => 'failed', 'message' => CanonicalUiText::get('payment.google_pay.closed')], 409);
            }
            try {
                $result = $this->provider()->charge($tokenId, $order, (string) $row['order_number'], (int) $row['total_minor'], (string) $row['currency'], $returnUrl);
            } catch (\RuntimeException) {
                return new JsonResponse(['status' => 'failed', 'message' => CanonicalUiText::get('payment.google_pay.declined')], 402);
            }
            if ($result['id'] !== '') {
                $this->db->update('mc_payment', ['provider_reference' => $result['id'], 'updated_at' => gmdate('Y-m-d H:i:s.u')], ['id' => (int) $row['payment_id']]);
            }
            if ($result['status'] === 'failed') {
                return new JsonResponse(['status' => 'failed', 'message' => CanonicalUiText::get('payment.google_pay.declined')], 402);
            }
            if ($result['status'] === 'paid') {
                $this->lifecycle->applyProviderStatus('google_pay', $result['id'], 'success', null, $result['amount_minor'], $result['currency'], null, $result['payload']);
            }
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        return new JsonResponse(['status' => $result['status'], 'redirect' => $result['redirect_url'] ?? $returnUrl]);
    }

    private function provider(): GooglePayPaymentProvider
    {
        $provider = $this->providers->require('google_pay');
        if (!$provider instanceof GooglePayPaymentProvider || !$provider->enabled()) {
            throw $this->createNotFoundException();
        }

        return $provider;
    }

    /** @return array<string,mixed>|null the order whose last payment was made with Google Pay */
    private function payableOrder(string $order): ?array
    {
        if (!Uuid::isValid($order)) {
            return null;
        }
        $row = $this->db->fetchAssociative(
            "SELECT o.order_number,o.total_minor,o.currency,p.id payment_id,p.status payment_status,s.name store_name,s.default_country
             FROM mc_sales_order o JOIN mc_payment p ON p.order_id=o.id JOIN mc_store s ON s.id=o.store_id
             WHERE o.public_id=? AND p.provider_code='google_pay' ORDER BY p.id DESC LIMIT 1",
            [Uuid::fromString($order)->toBinary()],
        );

        return is_array($row) ? $row : null;
    }
}
