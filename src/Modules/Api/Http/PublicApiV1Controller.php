<?php

declare(strict_types=1);

namespace Commerce\Modules\Api\Http;

use Commerce\Core\Api\ApiContractVersion;
use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Core\Platform\PlatformVersion;
use Commerce\Modules\Api\Application\ApiAccessException;
use Commerce\Modules\Api\Application\ApiAccessService;
use Commerce\Modules\Api\Application\ApiIdempotencyService;
use Commerce\Modules\Api\Infrastructure\DbalPublicApiQuery;
use Commerce\Modules\Api\Webhook\ApiWebhookSubscriptionService;
use Commerce\Modules\Cart\Application\CartMutationService;
use Commerce\Modules\Order\Application\CheckoutOrderService;
use Commerce\Modules\Payment\Application\PaymentFlowService;
use Commerce\Modules\Storefront\Domain\ProductCatalogFilter;
use Commerce\Modules\Storefront\Infrastructure\DbalStorefrontCatalogQuery;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1', name: 'api_v1_')]
final class PublicApiV1Controller extends AbstractController
{
    public function __construct(
        private readonly ApiAccessService $access,
        private readonly ApiIdempotencyService $idempotency,
        private readonly DbalPublicApiQuery $query,
        private readonly CartMutationService $cartMutations,
        private readonly CheckoutOrderService $orders,
        private readonly PaymentFlowService $paymentFlow,
        private readonly ApiWebhookSubscriptionService $webhooks,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'api' => ApiContractVersion::V1,
            'platform' => PlatformVersion::VERSION,
            'authentication' => CanonicalUiText::get('api.openapi.authentication'),
            'resources' => [
                'products' => '/api/v1/catalog/products', 'product' => '/api/v1/catalog/products/{public_id}',
                'customers' => '/api/v1/customers', 'orders' => '/api/v1/orders', 'carts' => '/api/v1/carts', 'openapi' => '/api/v1/openapi.json',
            ],
        ]);
    }

    #[Route('/catalog/products', name: 'products', methods: ['GET'])]
    public function products(Request $request, StorefrontContextResolver $contexts, DbalStorefrontCatalogQuery $catalog): JsonResponse
    {
        try {
            $context = $contexts->resolve($request); $this->access->require($request, 'catalog:read', $context->storeId);
            [$page,$limit]=$this->paging($request); $search=mb_substr(trim((string)$request->query->get('q','')),0,120,'UTF-8');
            $brandId=$this->positiveIntOrNull($request->query->get('brand')); $minPrice=$this->nonNegativeIntOrNull($request->query->get('min_price_minor')); $maxPrice=$this->nonNegativeIntOrNull($request->query->get('max_price_minor'));
            $sort=(string)$request->query->get('sort',ProductCatalogFilter::SORT_NEWEST);
            if(!in_array($sort,ProductCatalogFilter::SORTS,true))return $this->problem('invalid_sort',CanonicalUiText::get('api.error.invalid_sort'),400);
            if($minPrice!==null&&$maxPrice!==null&&$minPrice>$maxPrice)return $this->problem('invalid_price_range',CanonicalUiText::get('api.error.invalid_price_range'),400);
            $filter=new ProductCatalogFilter(search:$search,brandId:$brandId,inStockOnly:filter_var($request->query->get('in_stock',false),FILTER_VALIDATE_BOOL),minPriceMinor:$minPrice,maxPriceMinor:$maxPrice,sort:$sort,attributeFilters:$this->attributeFilters($request));
            $result=$catalog->products($context,null,$page,$limit,$search,$filter);
            return $this->collection(array_map([$this,'normalizeCard'],$result['items']),$result['total'],$result['page'],$result['pages'],$limit,$context->locale,$context->currency);
        } catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}
    }

    #[Route('/catalog/products/{publicId}', name: 'product', methods: ['GET'], requirements: ['publicId' => '[0-9a-fA-F-]{36}'])]
    public function product(string $publicId,Request $request,StorefrontContextResolver $contexts,DbalStorefrontCatalogQuery $catalog):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'catalog:read',$context->storeId);$product=$catalog->productByPublicId($context,$publicId);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}catch(\Throwable){return $this->problem('not_found',CanonicalUiText::get('api.error.product_not_found'),404);}
        if(!is_array($product))return $this->problem('not_found',CanonicalUiText::get('api.error.product_not_found'),404);unset($product['internal_id']);return $this->json(['data'=>$product,'meta'=>['locale'=>$context->locale,'currency'=>$context->currency]]);
    }

    #[Route('/customers', name: 'customers', methods: ['GET'])]
    public function customers(Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'customers:read',$context->storeId);[$page,$limit]=$this->paging($request);$result=$this->query->customers($context->storeId,$page,$limit,mb_substr(trim((string)$request->query->get('q','')),0,120,'UTF-8'));return $this->collection($result['items'],$result['total'],$page,$result['pages'],$limit,$context->locale,$context->currency);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}
    }

    #[Route('/customers/{publicId}', name: 'customer', methods: ['GET'], requirements: ['publicId'=>'[0-9a-fA-F-]{36}'])]
    public function customer(string $publicId,Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'customers:read',$context->storeId);$row=$this->query->customer($context->storeId,$publicId);if($row===null)return $this->problem('not_found',CanonicalUiText::get('api.error.customer_not_found'),404);return $this->json(['data'=>$row]);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}
    }

    #[Route('/orders', name: 'orders', methods: ['GET'])]
    public function orders(Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'orders:read',$context->storeId);[$page,$limit]=$this->paging($request);$result=$this->query->orders($context->storeId,$page,$limit,mb_substr(trim((string)$request->query->get('status','')),0,32,'UTF-8'),mb_substr(trim((string)$request->query->get('q','')),0,120,'UTF-8'));return $this->collection($result['items'],$result['total'],$page,$result['pages'],$limit,$context->locale,$context->currency);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}
    }

    #[Route('/orders/{publicId}', name: 'order', methods: ['GET'], requirements: ['publicId'=>'[0-9a-fA-F-]{36}'])]
    public function order(string $publicId,Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'orders:read',$context->storeId);$row=$this->query->order($context->storeId,$publicId);if($row===null)return $this->problem('not_found',CanonicalUiText::get('api.error.order_not_found'),404);return $this->json(['data'=>$row]);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}
    }

    #[Route('/carts', name: 'carts', methods: ['GET'])]
    public function carts(Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'carts:read',$context->storeId);[$page,$limit]=$this->paging($request);$result=$this->query->carts($context->storeId,$page,$limit,mb_substr(trim((string)$request->query->get('status','')),0,32,'UTF-8'));return $this->collection($result['items'],$result['total'],$page,$result['pages'],$limit,$context->locale,$context->currency);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}
    }

    #[Route('/carts/{publicId}', name: 'cart', methods: ['GET'], requirements: ['publicId'=>'[0-9a-fA-F-]{36}'])]
    public function cart(string $publicId,Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'carts:read',$context->storeId);$row=$this->query->cart($context->storeId,$publicId);if($row===null)return $this->problem('not_found',CanonicalUiText::get('api.error.cart_not_found'),404);return $this->json(['data'=>$row]);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}
    }

    #[Route('/carts', name: 'cart_create', methods: ['POST'])]
    public function createCart(Request $request, StorefrontContextResolver $contexts): JsonResponse
    {
        try {
            $context=$contexts->resolve($request);
            $token=$this->access->require($request,'carts:write',$context->storeId);
            $key=$this->idempotency->key($request); $hash=$this->idempotency->requestHash($request,$context->storeId);
            if(($replay=$this->idempotency->replay($token,$key,$hash))!==null)return $this->json($replay['body'],$replay['status']);
            $cart=$this->cartMutations->open($context,null); $publicId=$this->query->cartPublicIdByInternalId($context->storeId,$cart['id']);
            if($publicId===null)return $this->problem('operation_failed',CanonicalUiText::get('common.error.operation_failed'),500);
            $body=['data'=>['public_id'=>$publicId,'status'=>'active','currency'=>$context->currency]];
            $this->idempotency->remember($token,$key,$hash,201,$body); return $this->json($body,201);
        } catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);} catch(\Throwable){return $this->problem('operation_failed',CanonicalUiText::get('common.error.operation_failed'),500);}
    }

    #[Route('/carts/{cartPublicId}/items/{variantPublicId}', name: 'cart_item_put', methods: ['PUT'], requirements: ['cartPublicId'=>'[0-9a-fA-F-]{36}','variantPublicId'=>'[0-9a-fA-F-]{36}'])]
    public function putCartItem(string $cartPublicId,string $variantPublicId,Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try {
            $context=$contexts->resolve($request); $token=$this->access->require($request,'carts:write',$context->storeId);
            $key=$this->idempotency->key($request); $hash=$this->idempotency->requestHash($request,$context->storeId);
            if(($replay=$this->idempotency->replay($token,$key,$hash))!==null)return $this->json($replay['body'],$replay['status']);
            $payload=$this->jsonBody($request); $quantity=trim((string)($payload['quantity']??''));
            if($quantity===''||preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D',$quantity)!==1)return $this->problem('invalid_quantity',CanonicalUiText::get('api.error.invalid_quantity'),422);
            $cartId=$this->query->cartInternalId($context->storeId,$cartPublicId); if($cartId===null)return $this->problem('not_found',CanonicalUiText::get('api.error.cart_not_found'),404);
            $this->cartMutations->setVariantQuantity($context,$cartId,$variantPublicId,$quantity);
            $cart=$this->query->cart($context->storeId,$cartPublicId); if($cart===null)return $this->problem('not_found',CanonicalUiText::get('api.error.cart_not_found'),404);
            $body=['data'=>$cart]; $this->idempotency->remember($token,$key,$hash,200,$body); return $this->json($body);
        } catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);} catch(\DomainException|\InvalidArgumentException $e){return $this->problem('validation_failed',$e->getMessage(),422);} catch(\Throwable){return $this->problem('operation_failed',CanonicalUiText::get('common.error.operation_failed'),500);}
    }

    #[Route('/carts/{cartPublicId}/checkout', name: 'cart_checkout', methods: ['POST'], requirements: ['cartPublicId'=>'[0-9a-fA-F-]{36}'])]
    public function checkout(string $cartPublicId,Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try {
            $context=$contexts->resolve($request); $token=$this->access->require($request,'checkout:write',$context->storeId);
            $key=$this->idempotency->key($request); $hash=$this->idempotency->requestHash($request,$context->storeId);
            if(($replay=$this->idempotency->replay($token,$key,$hash))!==null)return $this->json($replay['body'],$replay['status']);
            $cartId=$this->query->cartInternalId($context->storeId,$cartPublicId); if($cartId===null)return $this->problem('not_found',CanonicalUiText::get('api.error.cart_not_found'),404);
            $payload=$this->jsonBody($request); $customerId=null; $customerPublic=trim((string)($payload['customer_public_id']??''));
            if($customerPublic!==''){ $customerId=$this->query->customerInternalId($context->storeId,$customerPublic); if($customerId===null)return $this->problem('not_found',CanonicalUiText::get('api.error.customer_not_found'),404); }
            unset($payload['customer_public_id']);
            $checkoutKey='api:'.$token->id.':'.$key;
            $order=$this->orders->place($context,$cartId,$payload,$checkoutKey,$customerId);
            try{$flow=$this->paymentFlow->afterOrderPlaced($order['public_id']);}catch(\Throwable){$flow=['redirect_url'=>null,'status'=>'payment_retry_required'];}
            $body=['data'=>['order'=>$order,'payment_status'=>(string)($flow['status']??'pending'),'redirect_url'=>$flow['redirect_url']??null]];
            $this->idempotency->remember($token,$key,$hash,201,$body); return $this->json($body,201);
        } catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);} catch(\DomainException|\InvalidArgumentException $e){return $this->problem('validation_failed',$e->getMessage(),422);} catch(\Throwable){return $this->problem('operation_failed',CanonicalUiText::get('common.error.operation_failed'),500);}
    }

    #[Route('/webhooks', name: 'webhooks', methods: ['GET'])]
    public function webhooks(Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'webhooks:manage',$context->storeId);return $this->json(['data'=>$this->webhooks->list($context->storeId)]);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}catch(\Throwable){return $this->problem('operation_failed',CanonicalUiText::get('common.error.operation_failed'),500);}
    }

    #[Route('/webhooks', name: 'webhook_create', methods: ['POST'])]
    public function createWebhook(Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{
            $context=$contexts->resolve($request);$token=$this->access->require($request,'webhooks:manage',$context->storeId);$key=$this->idempotency->key($request);$hash=$this->idempotency->requestHash($request,$context->storeId);$payload=$this->jsonBody($request);
            $events=is_array($payload['events']??null)?$payload['events']:[];
            $created=$this->webhooks->create($context->storeId,$token->id,$key,$hash,(string)($payload['name']??''),(string)($payload['target_url']??''),$events);
            return $this->json(['data'=>$created],201);
        }catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}catch(\DomainException|\InvalidArgumentException $e){return $this->problem('validation_failed',$e->getMessage(),422);}catch(\Throwable){return $this->problem('operation_failed',CanonicalUiText::get('common.error.operation_failed'),500);}
    }

    #[Route('/webhooks/{publicId}', name: 'webhook_delete', methods: ['DELETE'], requirements: ['publicId'=>'[0-9a-fA-F-]{36}'])]
    public function deleteWebhook(string $publicId,Request $request,StorefrontContextResolver $contexts):JsonResponse
    {
        try{$context=$contexts->resolve($request);$this->access->require($request,'webhooks:manage',$context->storeId);$this->webhooks->disable($context->storeId,$publicId);return $this->json(['data'=>['public_id'=>$publicId,'status'=>'disabled']]);}catch(ApiAccessException $e){return $this->problem($e->apiCode,$e->getMessage(),$e->status);}catch(\Throwable){return $this->problem('operation_failed',CanonicalUiText::get('common.error.operation_failed'),500);}
    }

    #[Route('/openapi.json', name: 'openapi', methods: ['GET'])]
    public function openApi():JsonResponse
    {
        $security=[['bearerAuth'=>[]]];
        return $this->json(['openapi'=>'3.1.0','info'=>['title'=>CanonicalUiText::get('api.openapi.title'),'version'=>ApiContractVersion::V1],
            'components'=>['securitySchemes'=>['bearerAuth'=>['type'=>'http','scheme'=>'bearer','bearerFormat'=>'opaque']]],
            'paths'=>[
                '/api/v1/catalog/products'=>['get'=>['security'=>$security,'x-scope'=>'catalog:read','summary'=>CanonicalUiText::get('api.openapi.products_list'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.product_collection')],'401'=>['description'=>CanonicalUiText::get('api.openapi.auth_required')],'429'=>['description'=>CanonicalUiText::get('api.openapi.rate_limited')]]]],
                '/api/v1/catalog/products/{publicId}'=>['get'=>['security'=>$security,'x-scope'=>'catalog:read','summary'=>CanonicalUiText::get('api.openapi.product_get'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.product')],'404'=>['description'=>CanonicalUiText::get('api.openapi.not_found')]]]],
                '/api/v1/customers'=>['get'=>['security'=>$security,'x-scope'=>'customers:read','summary'=>CanonicalUiText::get('api.openapi.customers_list'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.customer_collection')]]]],
                '/api/v1/customers/{publicId}'=>['get'=>['security'=>$security,'x-scope'=>'customers:read','summary'=>CanonicalUiText::get('api.openapi.customer_get'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.customer')],'404'=>['description'=>CanonicalUiText::get('api.openapi.not_found')]]]],
                '/api/v1/orders'=>['get'=>['security'=>$security,'x-scope'=>'orders:read','summary'=>CanonicalUiText::get('api.openapi.orders_list'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.order_collection')]]]],
                '/api/v1/orders/{publicId}'=>['get'=>['security'=>$security,'x-scope'=>'orders:read','summary'=>CanonicalUiText::get('api.openapi.order_get'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.order')],'404'=>['description'=>CanonicalUiText::get('api.openapi.not_found')]]]],
                '/api/v1/carts'=>[
                    'get'=>['security'=>$security,'x-scope'=>'carts:read','summary'=>CanonicalUiText::get('api.openapi.carts_list'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.cart_collection')]]],
                    'post'=>['security'=>$security,'x-scope'=>'carts:write','summary'=>CanonicalUiText::get('api.openapi.cart_create'),'parameters'=>[['name'=>'Idempotency-Key','in'=>'header','required'=>true]],'responses'=>['201'=>['description'=>CanonicalUiText::get('api.openapi.cart_created')]]],
                ],
                '/api/v1/carts/{publicId}'=>['get'=>['security'=>$security,'x-scope'=>'carts:read','summary'=>CanonicalUiText::get('api.openapi.cart_get'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.cart')],'404'=>['description'=>CanonicalUiText::get('api.openapi.not_found')]]]],
                '/api/v1/carts/{cartPublicId}/items/{variantPublicId}'=>['put'=>['security'=>$security,'x-scope'=>'carts:write','summary'=>CanonicalUiText::get('api.openapi.cart_item_put'),'parameters'=>[['name'=>'Idempotency-Key','in'=>'header','required'=>true]],'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.cart')]]]],
                '/api/v1/carts/{cartPublicId}/checkout'=>['post'=>['security'=>$security,'x-scope'=>'checkout:write','summary'=>CanonicalUiText::get('api.openapi.checkout_create'),'parameters'=>[['name'=>'Idempotency-Key','in'=>'header','required'=>true]],'responses'=>['201'=>['description'=>CanonicalUiText::get('api.openapi.order')]]]],
                '/api/v1/webhooks'=>[
                    'get'=>['security'=>$security,'x-scope'=>'webhooks:manage','summary'=>CanonicalUiText::get('api.openapi.webhooks_list'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.webhook_collection')]]],
                    'post'=>['security'=>$security,'x-scope'=>'webhooks:manage','summary'=>CanonicalUiText::get('api.openapi.webhook_create'),'parameters'=>[['name'=>'Idempotency-Key','in'=>'header','required'=>true]],'responses'=>['201'=>['description'=>CanonicalUiText::get('api.openapi.webhook_created')]]],
                ],
                '/api/v1/webhooks/{publicId}'=>['delete'=>['security'=>$security,'x-scope'=>'webhooks:manage','summary'=>CanonicalUiText::get('api.openapi.webhook_disable'),'responses'=>['200'=>['description'=>CanonicalUiText::get('api.openapi.webhook_disabled')]]]],
            ]]);
    }


    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        if(strlen((string)$request->getContent())>1024*1024)throw new \InvalidArgumentException(CanonicalUiText::get('api.error.payload_too_large'));
        try{$data=json_decode((string)$request->getContent(),true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \InvalidArgumentException(CanonicalUiText::get('api.error.invalid_json'));}
        if(!is_array($data))throw new \InvalidArgumentException(CanonicalUiText::get('api.error.invalid_json'));
        return $data;
    }

    /** @return array<string,mixed> */ private function normalizeCard(array $item):array{unset($item['internal_id']);return $item;}
    /** @return array{0:int,1:int} */ private function paging(Request $request):array{return [max(1,min(100000,(int)$request->query->get('page',1))),max(1,min(100,(int)$request->query->get('limit',25)))];}
    /** @return array<string,list<string>> */ private function attributeFilters(Request $request):array{$raw=$request->query->all('attr');if(!is_array($raw))return[];$result=[];foreach(array_slice($raw,0,12,true)as$code=>$values){if(!is_string($code)||preg_match('/^[A-Za-z0-9_.-]{1,128}$/D',$code)!==1)continue;$list=is_array($values)?$values:[$values];$clean=[];foreach(array_slice($list,0,12)as$value){if(is_string($value)&&$value!==''&&strlen($value)<=96)$clean[]=$value;}if($clean!==[])$result[$code]=array_values(array_unique($clean));}return$result;}
    private function positiveIntOrNull(mixed $value):?int{if($value===null||$value==='')return null;$int=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);return$int===false?null:(int)$int;}
    private function nonNegativeIntOrNull(mixed $value):?int{if($value===null||$value==='')return null;$int=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);return$int===false?null:(int)$int;}
    /** @param list<array<string,mixed>> $items */ private function collection(array $items,int $total,int $page,int $pages,int $limit,string $locale,string $currency):JsonResponse{return $this->json(['data'=>$items,'meta'=>['page'=>$page,'pages'=>$pages,'total'=>$total,'limit'=>$limit,'locale'=>$locale,'currency'=>$currency]]);}
    private function problem(string $code,string $detail,int $status):JsonResponse{return $this->json(['type'=>'about:blank','title'=>$code,'status'=>$status,'detail'=>$detail],$status,['Content-Type'=>'application/problem+json']);}
}
