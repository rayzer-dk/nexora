<?php

declare(strict_types=1);

namespace Commerce\Modules\Checkout\Http;

use Commerce\Modules\Cart\Application\CartMutationService;
use Commerce\Modules\Cart\Application\DbalCartQuery;
use Commerce\Modules\B2B\Application\B2bCommerceService;
use Commerce\Modules\Checkout\Application\CheckoutLayoutService;
use Commerce\Modules\Checkout\Application\CheckoutLeadService;
use Commerce\Modules\Checkout\Application\CheckoutMethodSettings;
use Commerce\Modules\Order\Application\OrderConfirmationQuery;
use Commerce\Modules\Storefront\Infrastructure\PickupPointRepository;
use Commerce\Modules\Customer\Domain\CustomerUser;
use Commerce\Modules\Order\Application\CheckoutOrderService;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\Payment\Application\PaymentFlowService;
use Commerce\Modules\Promotion\Application\PromotionEngine;
use Commerce\Modules\Marketing\Application\MarketingAttributionService;
use Commerce\Modules\Rewards\Application\LoyaltyService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Commerce\Modules\Storefront\Infrastructure\StorefrontMoneyFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly \Commerce\Modules\Shipping\Application\ShippingCountryService $shippingCountries,
        private readonly StorefrontContextResolver $contexts,
        private readonly CartMutationService $carts,
        private readonly DbalCartQuery $cartQuery,
        private readonly CheckoutOrderService $orders,
        private readonly PaymentProviderRegistry $payments,
        private readonly PaymentFlowService $paymentFlow,
        private readonly PromotionEngine $promotions,
        private readonly StorefrontMoneyFormatter $money,
        private readonly CheckoutLayoutService $checkoutLayout,
        private readonly MarketingAttributionService $attribution,
        private readonly B2bCommerceService $b2b,
        private readonly LoyaltyService $loyalty,
        private readonly CheckoutMethodSettings $methodSettings,
        private readonly PickupPointRepository $pickupPoints,
        private readonly OrderConfirmationQuery $confirmation,
        private readonly \Commerce\Modules\Rewards\Application\GiftCardService $giftCards,
        private readonly CheckoutLeadService $leads,
    ) {}

    /** Delivery and payment fee for the method the shopper has picked so far, for the advisory totals. */
    private function previewShipping(Request $request, int $storeId, int $afterDiscountMinor): int
    {
        $carrier = trim((string) $request->request->get('carrier'));
        $pay = trim((string) $request->request->get('payment_method'));
        $fee = 0;
        if ($carrier !== '' && in_array($carrier, CheckoutMethodSettings::DELIVERY, true)) {
            $fee += $this->methodSettings->fee($storeId, $carrier, $afterDiscountMinor);
        }
        if ($pay !== '' && in_array($pay, CheckoutMethodSettings::PAYMENT, true)) {
            $fee += $this->methodSettings->fee($storeId, $pay, $afterDiscountMinor);
        }

        return $fee;
    }

    /**
     * For every delivery and payment method: the owner's title and text (per language, may be empty) and how much it costs.
     *
     * @return array<string,array{title:string,text:string,fee:string,free_over:string}>
     */
    private function methodLabels(int $storeId, string $locale, string $currency): array
    {
        $out = [];
        foreach ($this->methodSettings->config($storeId) as $code => $c) {
            $out[$code] = [
                'title' => $this->methodSettings->label($storeId, $code, 'title', $locale),
                'text' => $this->methodSettings->label($storeId, $code, 'text', $locale),
                'fee' => $c['fee_minor'] > 0 ? $this->money->format($c['fee_minor'], $currency, $locale) : '',
                'free_over' => $c['fee_minor'] > 0 && $c['free_over_minor'] > 0 ? $this->money->format($c['free_over_minor'], $currency, $locale) : '',
            ];
        }

        return $out;
    }

    #[Route('/checkout/lead', name: 'storefront_checkout_lead', methods: ['POST'], priority: 100)]
    public function lead(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('checkout_lead', (string) $request->request->get('_token'))) {
            return new JsonResponse(['ok' => false], 403);
        }
        $context = $this->contexts->resolve($request);
        $cart = $this->carts->find($context, $request->cookies->get('mc_cart'));
        if ($cart === null) {
            return new JsonResponse(['ok' => false], 404);
        }
        $stored = $this->leads->capture($context->storeId, (int) $cart['id'], (string) $request->request->get('name'), (string) $request->request->get('email'), (string) $request->request->get('phone'), $context->locale);

        return new JsonResponse(['ok' => $stored]);
    }

    #[Route('/checkout', name: 'storefront_checkout', methods: ['GET'], priority: 100)]
    public function show(Request $request): Response
    {
        $context = $this->contexts->resolve($request);
        $cart = $this->carts->find($context, $request->cookies->get('mc_cart'));
        if ($cart === null) return $this->redirectToRoute('storefront_cart');
        $context = $this->carts->contextFor($context, $cart);
        if (!$this->shippingCountries->allows($context->storeId, $context->countryCode)) $this->addFlash('checkout_error', \Commerce\Core\I18n\CanonicalUiText::get('checkout_country_blocked'));
        $customer=$this->getUser(); if($customer instanceof CustomerUser)$this->carts->bindCustomer($cart['id'],$context->storeId,$customer->id());
        $summary = $this->cartQuery->summary($cart['id'], $context);
        if ($summary['items'] === []) return $this->redirectToRoute('storefront_cart');
        $key = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $request->getSession()->set('checkout.idempotency_key', $key);
        $layout = $this->checkoutLayout->active($context->storeId);
        $customerId=$customer instanceof CustomerUser?$customer->id():null;
        $b2b=$this->b2b->membership($context->storeId,$customerId);
        $loyaltyAccount=$customerId!==null?$this->loyalty->account($context->storeId,$customerId):null;
        $loyaltyConfig=$this->loyalty->config($context->storeId);
        $methods=array_values(array_filter($this->payments->enabledMethods(),fn($m):bool=>($m->code!=='b2b_invoice'||$b2b!==null)&&$this->methodSettings->isEnabled($context->storeId,$m->code)));
        // Pay-on-receipt first: it is the most requested method for parcel and pickup orders.
        usort($methods,static fn($a,$b):int=>[$a->code==='cash_on_delivery'?0:1]<=>[$b->code==='cash_on_delivery'?0:1]);
        $deliveryOptions=array_values(array_filter([...CheckoutMethodSettings::DELIVERY,...$this->methodSettings->customCodes('delivery')],fn(string $code):bool=>$this->methodSettings->isEnabled($context->storeId,$code)));
        $pickupPoints=in_array('self_pickup',$deliveryOptions,true)?$this->pickupPoints->forCheckout($context->storeId,$context->storeName):[];
        $old=$this->flashBag($request)?->get('checkout_old')??[]; $old=is_array($old[0]??null)?$old[0]:[];
        $response = $this->render('@storefront/checkout/show.html.twig', [
            'page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.oformlennia_zamovlennia'),'store_name'=>$context->storeName,'cart'=>$summary,'country_code'=>$context->countryCode,'delivery_regions'=>$this->shippingCountries->enabledRegions($context->storeId,$context->countryCode),'checkout_layout'=>$layout,
            'method_config'=>$this->methodLabels($context->storeId,$context->locale,$context->currency),'payment_methods'=>$methods,'checkout_key'=>$key,'delivery_options'=>$deliveryOptions,'pickup_points'=>$pickupPoints,'old'=>$old,'custom_delivery'=>$this->methodSettings->customCodes('delivery'),'custom_icons'=>array_column($this->methodSettings->custom(),'icon','code'),'customer_user'=>$this->getUser() instanceof CustomerUser ? $this->getUser() : null,'b2b_company'=>$b2b,'loyalty_account'=>$loyaltyAccount,'loyalty_config'=>$loyaltyConfig,
            'seo_head'=>['canonical'=>$request->getSchemeAndHttpHost().'/checkout','robots'=>'noindex,nofollow'],
        ]);
        if ($cart['created']) $response->headers->setCookie(Cookie::create('mc_cart',$cart['token'])->withExpires(new \DateTimeImmutable('+7 days'))->withPath('/')->withSecure($request->isSecure())->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));
        return $response;
    }


    #[Route('/checkout/promotion/preview', name: 'storefront_checkout_promotion_preview', methods: ['POST'], priority: 100)]
    public function promotionPreview(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('checkout_promotion', (string)$request->request->get('_token'))) return $this->json(['ok'=>false,'message'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.sesiia_formy_zavershylas')], 403);
        $context=$this->contexts->resolve($request); $cart=$this->carts->open($context,$request->cookies->get('mc_cart')); $context=$this->carts->contextFor($context,$cart);
        $customer=$this->getUser(); if($customer instanceof CustomerUser)$this->carts->bindCustomer($cart['id'],$context->storeId,$customer->id());
        $result=$this->promotions->calculateForCart($context->storeId,$cart['id'],trim((string)$request->request->get('coupon_code')) ?: null,$customer instanceof CustomerUser ? $customer->id() : null,trim((string)$request->request->get('email')) ?: null);
        $shipFee=$this->previewShipping($request,$context->storeId,$result->totalMinor);
        return $this->json([
            'ok'=>$result->couponMessage===null,
            'message'=>$result->couponMessage ?? ($result->discountMinor>0 ? \Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.znyzhku_zastosovano') : \Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.dlia_koshyka_nemaie_aktyvnoi_znyzhky')),
            'subtotal_minor'=>$result->subtotalMinor,'discount_minor'=>$result->discountMinor,'total_minor'=>$result->totalMinor+$shipFee,'shipping_minor'=>$shipFee,
            'subtotal'=>$this->money->format($result->subtotalMinor,$context->currency,$context->locale),
            'discount'=>$this->money->format($result->discountMinor,$context->currency,$context->locale),
            'shipping'=>$shipFee>0?$this->money->format($shipFee,$context->currency,$context->locale):'',
            'total'=>$this->money->format($result->totalMinor+$shipFee,$context->currency,$context->locale),
            'applied'=>$result->applied,
        ]);
    }

    #[Route('/checkout/rewards/preview', name: 'storefront_checkout_rewards_preview', methods: ['POST'], priority: 100)]
    public function rewardsPreview(Request $request): JsonResponse
    {
        $text = static fn (string $key, array $replace = []): string => \Commerce\Core\I18n\CanonicalUiText::get($key, $replace);
        if (!$this->isCsrfTokenValid('checkout_promotion', (string)$request->request->get('_token'))) return $this->json(['ok'=>false,'message'=>$text('reward_gift_invalid')], 403);
        $context=$this->contexts->resolve($request); $cart=$this->carts->open($context,$request->cookies->get('mc_cart')); $context=$this->carts->contextFor($context,$cart);
        $customer=$this->getUser(); $customerId=$customer instanceof CustomerUser?$customer->id():null;
        $result=$this->promotions->calculateForCart($context->storeId,$cart['id'],trim((string)$request->request->get('coupon_code')) ?: null,$customerId,trim((string)$request->request->get('email')) ?: null);
        $shipFee=$this->previewShipping($request,$context->storeId,$result->totalMinor);
        $left=$result->totalMinor+$shipFee; $messages=[]; $ok=true; $giftMinor=0; $loyaltyMinor=0;
        $code=trim((string)$request->request->get('gift_card_code'));
        if ($code !== '') {
            $gift=$this->giftCards->preview($context->storeId,$code,$context->currency,$left);
            if ($gift === null) { $ok=false; $messages[]=$text('reward_gift_invalid'); }
            else { $giftMinor=(int)$gift['amount_minor']; $left-=$giftMinor; $messages[]=$text('reward_gift_applied',['amount'=>$this->money->format($giftMinor,$context->currency,$context->locale)]); }
        }
        $points=max(0,(int)$request->request->get('loyalty_points'));
        if ($points > 0) {
            $cfg=$this->loyalty->config($context->storeId); $min=(int)($cfg['min_redeem_points']??0); $per=max(1,(int)($cfg['redeem_minor_per_point']??1));
            if ($customerId === null) { $ok=false; $messages[]=$text('reward_points_login'); }
            else {
                $balance=(int)($this->loyalty->account($context->storeId,$customerId)['points_balance']??0);
                $use=min($points,$balance,intdiv(max(0,$left),$per));
                if (!(bool)($cfg['enabled']??false) || $use < $min) { $ok=false; $messages[]=$text('reward_points_invalid',['min'=>$min]); }
                else { $loyaltyMinor=$use*$per; $left-=$loyaltyMinor; $messages[]=$text('reward_points_applied',['amount'=>$this->money->format($loyaltyMinor,$context->currency,$context->locale)]); }
            }
        }
        if ($messages === []) { $ok=false; $messages[]=$text('reward_none'); }
        return $this->json(['ok'=>$ok,'message'=>implode(' ',$messages),'gift_minor'=>$giftMinor,'loyalty_minor'=>$loyaltyMinor,'shipping_minor'=>$shipFee,'shipping'=>$shipFee>0?$this->money->format($shipFee,$context->currency,$context->locale):'','total_minor'=>max(0,$left),'total'=>$this->money->format(max(0,$left),$context->currency,$context->locale)]);
    }

    #[Route('/checkout/place', name: 'storefront_checkout_place', methods: ['POST'], priority: 100)]
    public function place(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('checkout_place', (string)$request->request->get('_token'))) throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));
        $context=$this->contexts->resolve($request); $cart=$this->carts->open($context,$request->cookies->get('mc_cart')); $context=$this->carts->contextFor($context,$cart);
        if (!$this->shippingCountries->allows($context->storeId, $context->countryCode)) { $this->addFlash('checkout_error', \Commerce\Core\I18n\CanonicalUiText::get('checkout_country_blocked')); return $this->redirectToRoute('storefront_checkout'); }
        $key=(string)$request->request->get('checkout_key');
        if ($key==='' || !hash_equals((string)$request->getSession()->get('checkout.idempotency_key',''),$key)) { $this->addFlash('checkout_error',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.sesiia_oformlennia_zastarila_onovit_storinku')); return $this->redirectToRoute('storefront_checkout'); }
        try { $customer=$this->getUser(); if($customer instanceof CustomerUser)$this->carts->bindCustomer($cart['id'],$context->storeId,$customer->id()); $input=$request->request->all(); if(str_starts_with((string)($input['carrier']??''),'custom_ship_')){ $input['delivery_manual']=trim((string)($input['custom_address']??'')); } $order=$this->orders->place($context,$cart['id'],$input,$key,$customer instanceof CustomerUser ? $customer->id() : null); $this->attribution->attachOrder($order['public_id'],$request->getSession()); }
        catch (\DomainException $e) {
            $this->addFlash('checkout_error',$e->getMessage());
            // Keep what the buyer typed (never the card/gift codes) so a validation error does not wipe the form.
            $keep=[]; foreach(['name','phone','email','customer_comment','company_name','company_tax_id','carrier','city_id','city_name','point_id','point_name','delivery_manual','custom_address','delivery_region','payment_method','coupon_code','purchase_order_number'] as $field){$keep[$field]=mb_substr((string)$request->request->get($field,''),0,500);}
            $this->flashBag($request)?->set('checkout_old',[$keep]);
            return $this->redirectToRoute('storefront_checkout');
        }
        try { $flow=$this->paymentFlow->afterOrderPlaced($order['public_id']); }
        catch (\Throwable $e) {
            $this->addFlash('payment_issue',\Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.zamovlennia_stvoreno_ale_platizhnyi_servis_tymchasov'));
            $flow=['redirect_url'=>null,'status'=>'payment_retry_required'];
        }
        $response=$flow['redirect_url'] ? $this->redirect((string)$flow['redirect_url']) : $this->redirectToRoute('storefront_checkout_success',['order'=>$order['public_id']]);
        $response->headers->clearCookie('mc_cart','/');
        return $response;
    }

    #[Route('/checkout/success/{order}', name: 'storefront_checkout_success', methods: ['GET'], priority: 100)]
    public function success(string $order, Request $request): Response
    {
        $context=$this->contexts->resolve($request);
        return $this->render('@storefront/checkout/success.html.twig',['page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.checkout.http.checkoutcontroller.zamovlennia_pryiniato'),'order_public_id'=>$order,'order_info'=>$this->confirmation->find($order,$context->storeId,$context->locale),'seo_head'=>['robots'=>'noindex,nofollow']]);
    }

    private function flashBag(Request $request): ?FlashBagInterface
    {
        $session = $request->getSession();

        return $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : null;
    }
}
