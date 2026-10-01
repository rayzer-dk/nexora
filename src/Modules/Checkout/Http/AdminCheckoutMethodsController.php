<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Http;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Commerce\Modules\Checkout\Application\CheckoutMethodSettings;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\Storefront\Infrastructure\PickupPointRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Admin → Shipping → Delivery and payment methods: switch checkout options (self pickup, cash on delivery, carriers) on or off. */
final class AdminCheckoutMethodsController extends AbstractController
{
    public function __construct(
        private readonly AdminContextResolver $contexts,
        private readonly CheckoutMethodSettings $settings,
        private readonly PaymentProviderRegistry $payments,
        private readonly PickupPointRepository $pickup,
    ) {
    }

    #[Route('/admin/shipments/methods', name: 'admin_shipment_methods', methods: ['GET'], priority: 10)]
    public function index(Request $request): Response
    {
        $ctx = $this->contexts->resolve($request);
        $online = [];
        foreach ($this->payments->enabledMethods() as $method) {
            if (!in_array($method->code, [...CheckoutMethodSettings::PAYMENT, 'b2b_invoice'], true)) {
                $online[] = $method->name;
            }
        }

        return $this->render('@storefront/admin/shipping/methods.html.twig', [
            'enabled' => $this->settings->all($ctx->storeId),
            'delivery_codes' => CheckoutMethodSettings::DELIVERY,
            'payment_codes' => CheckoutMethodSettings::PAYMENT,
            'other_payments' => $online,
            'pickup_points' => count($this->pickup->all($ctx->storeId, true)),
        ]);
    }

    #[Route('/admin/shipments/methods/save', name: 'admin_shipment_methods_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('shipping_methods', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException(CanonicalUiText::get('common.security.invalid_csrf'));
        }
        $ctx = $this->contexts->resolve($request);
        $this->settings->save($ctx->storeId, array_map('strval', (array) $request->request->all('methods')));
        $this->addFlash('success', CanonicalUiText::get('admin.checkout_methods.saved'));

        return $this->redirectToRoute('admin_shipment_methods');
    }
}
