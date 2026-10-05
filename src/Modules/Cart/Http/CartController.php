<?php

declare(strict_types=1);

namespace Commerce\Modules\Cart\Http;

use Commerce\Modules\Cart\Application\CartMutationService;
use Commerce\Modules\Cart\Application\DbalCartQuery;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Commerce\Modules\Customer\Domain\CustomerUser;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CartController extends AbstractController
{
    public function __construct(
        private readonly StorefrontContextResolver $contexts,
        private readonly CartMutationService $mutations,
        private readonly DbalCartQuery $query,
        private readonly LoggerInterface $logger,
        private readonly \Commerce\Modules\Storefront\Application\CartLayoutService $layout,
        private readonly \Commerce\Modules\Checkout\Application\CheckoutMethodSettings $methodSettings,
        private readonly \Commerce\Modules\Storefront\Infrastructure\StorefrontMoneyFormatter $money,
    ) {
    }

    #[Route('/cart', name:'storefront_cart', methods:['GET'], priority:100)]
    public function show(Request $request): Response
    {
        // Viewing the cart must not create one: crawlers and first-time visitors follow the header link.
        $context=$this->contexts->resolve($request); $cart=$this->mutations->find($context,$request->cookies->get('mc_cart'));
        if($cart!==null){$this->bind($cart['id'],$context->storeId); $context=$this->mutations->contextFor($context,$cart);}
        $summary=$cart!==null?$this->query->summary($cart['id'],$context):$this->query->emptySummary($context); $threshold=$summary['requires_shipping']?$this->methodSettings->freeShippingThreshold($context->storeId):null; $freeShip=null; if($threshold!==null&&$summary['count']>0){$paid=$summary['total_minor']; $freeShip=['reached'=>$paid>=$threshold,'percent'=>(int)min(100,floor($paid*100/$threshold)),'remaining'=>$this->money->format(max(0,$threshold-$paid),$summary['currency'],$context->locale),'threshold'=>$this->money->format($threshold,$summary['currency'],$context->locale)];} $response=$this->render('@storefront/cart/show.html.twig',['free_shipping'=>$freeShip,'cart_layout'=>$this->layout->active($context->storeId),'page_title'=>\Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.cartcontroller.koshyk'),'store_name'=>$context->storeName,'cart'=>$summary,'seo_head'=>['canonical'=>$request->getSchemeAndHttpHost().'/cart','robots'=>'noindex,follow']]);
        if($cart!==null){$this->attachCookie($response,$request,$cart);} return $response;
    }

    /** HTML fragment of the slide-in cart (header cart icon). Read-only: it never creates a cart. */
    #[Route('/cart/drawer', name:'storefront_cart_drawer', methods:['GET'], priority:100)]
    public function drawer(Request $request): Response
    {
        $context=$this->contexts->resolve($request); $cart=$this->mutations->find($context,$request->cookies->get('mc_cart'));
        if($cart!==null){$context=$this->mutations->contextFor($context,$cart);}
        $response=$this->render('@storefront/cart/_drawer.html.twig',['cart'=>$cart!==null?$this->query->summary($cart['id'],$context):$this->query->emptySummary($context)]);
        $response->headers->set('Cache-Control','no-store, private');
        return $response;
    }

    #[Route('/cart/add', name:'storefront_cart_add', methods:['POST'], priority:100)]
    public function add(Request $request): Response
    {
        $this->guard($request);
        $context = $this->contexts->resolve($request);
        $cart = $this->mutations->open($context, $request->cookies->get('mc_cart')); $this->bind($cart['id'],$context->storeId); $context=$this->mutations->contextFor($context,$cart);
        try {
            $this->mutations->add($context, $cart['id'], (string) $request->request->get('variant_id'), (string) $request->request->get('quantity', '1'));
            if ($this->wantsJson($request)) {
                $summary = $this->query->summary($cart['id'], $context);
                $response = new JsonResponse([
                    'ok' => true,
                    'message' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.cartcontroller.tovar_dodano_do_koshyka'),
                    'cart' => ['count' => $summary['count'], 'subtotal' => $summary['subtotal'], 'currency' => $summary['currency']],
                    'checkout_url' => '/checkout',
                    'cart_url' => '/cart',
                ]);
                $this->attachCookie($response, $request, $cart);
                return $response;
            }
            $this->addFlash('success', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.cartcontroller.tovar_dodano_do_koshyka'));
        } catch (\DomainException|\InvalidArgumentException $e) {
            if ($this->wantsJson($request)) {
                $response = new JsonResponse(['ok' => false, 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
                $this->attachCookie($response, $request, $cart);
                return $response;
            }
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Cart add failed unexpectedly.', [
                'exception' => $e,
                'store_id' => $context->storeId,
                'cart_id' => $cart['id'],
            ]);
            if ($this->wantsJson($request)) {
                $response = new JsonResponse(['ok' => false, 'message' => \Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.cartcontroller.ne_vdalosia_dodaty_tovar_povtorit_sprobu')], Response::HTTP_SERVICE_UNAVAILABLE);
                $this->attachCookie($response, $request, $cart);
                return $response;
            }
            $this->addFlash('error', \Commerce\Core\I18n\CanonicalUiText::get('php.modules.cart.http.cartcontroller.ne_vdalosia_dodaty_tovar_povtorit_sprobu'));
        }
        $response = (string) $request->request->get('next') === 'checkout' ? $this->redirectToRoute('storefront_checkout') : $this->redirectToRoute('storefront_cart');
        $this->attachCookie($response, $request, $cart);
        return $response;
    }

    #[Route('/cart/{itemId}/update', name:'storefront_cart_update', methods:['POST'], requirements:['itemId'=>'\\d+'], priority:100)]
    public function update(Request $request,int $itemId): Response
    {
        $this->guard($request); $context=$this->contexts->resolve($request); $cart=$this->mutations->open($context,$request->cookies->get('mc_cart')); $this->bind($cart['id'],$context->storeId); $context=$this->mutations->contextFor($context,$cart);
        try{
            $this->mutations->update($context,$cart['id'],$itemId,(string)$request->request->get('quantity','1'));
            if($this->wantsJson($request)){ $response=new JsonResponse(['ok'=>true,'cart'=>$this->query->summary($cart['id'],$context)]); $this->attachCookie($response,$request,$cart); return $response; }
        }catch(\Throwable $e){ if($this->wantsJson($request)){ $response=new JsonResponse(['ok'=>false,'message'=>\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed')],500); $this->attachCookie($response,$request,$cart); return $response;} $this->addFlash('error',\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));}
        $response=$this->redirectToRoute('storefront_cart'); $this->attachCookie($response,$request,$cart); return $response;
    }

    #[Route('/cart/{itemId}/remove', name:'storefront_cart_remove', methods:['POST'], requirements:['itemId'=>'\\d+'], priority:100)]
    public function remove(Request $request,int $itemId): Response
    {
        $this->guard($request); $context=$this->contexts->resolve($request); $cart=$this->mutations->open($context,$request->cookies->get('mc_cart')); $this->bind($cart['id'],$context->storeId); $context=$this->mutations->contextFor($context,$cart); $this->mutations->remove($cart['id'],$itemId);
        if($this->wantsJson($request)){ $response=new JsonResponse(['ok'=>true,'cart'=>$this->query->summary($cart['id'],$context)]); $this->attachCookie($response,$request,$cart); return $response; }
        $response=$this->redirectToRoute('storefront_cart'); $this->attachCookie($response,$request,$cart); return $response;
    }

    private function bind(int $cartId,int $storeId):void{$user=$this->getUser();if($user instanceof CustomerUser)$this->mutations->bindCustomer($cartId,$storeId,$user->id());}

    private function wantsJson(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            || str_contains(strtolower((string) $request->headers->get('Accept', '')), 'application/json');
    }

    private function guard(Request $request):void
    {
        if(!$this->isCsrfTokenValid('cart_mutation',(string)$request->request->get('_token'))){throw $this->createAccessDeniedException(\Commerce\Core\I18n\CanonicalUiText::get('common.security.invalid_csrf'));}
    }
    /** @param array{id:int,token:string,created:bool} $cart */
    private function attachCookie(Response $response,Request $request,array $cart):void
    {
        if(!$cart['created']){return;} $response->headers->setCookie(Cookie::create('mc_cart',$cart['token'])->withExpires(new \DateTimeImmutable('+7 days'))->withPath('/')->withSecure($request->isSecure())->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));
    }
}
