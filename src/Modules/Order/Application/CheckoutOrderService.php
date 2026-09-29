<?php

declare(strict_types=1);

namespace Commerce\Modules\Order\Application;

use Commerce\Core\Event\DomainEventFactory;
use Commerce\Core\Event\EventBusInterface;
use Commerce\Core\Event\EventNames;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Catalog\Measurement\Quantity;
use Commerce\Modules\B2B\Application\B2bCommerceService;
use Commerce\Modules\Rewards\Application\GiftCardService;
use Commerce\Modules\Rewards\Application\LoyaltyService;
use Commerce\Modules\Payment\Application\PaymentProviderRegistry;
use Commerce\Modules\Checkout\Application\CheckoutLayoutService;
use Commerce\Modules\Promotion\Application\PromotionEngine;
use Commerce\Modules\Promotion\Application\PromotionRedemptionRecorder;
use Commerce\Modules\Storefront\Domain\StorefrontContext;
use Commerce\Modules\Customer\Application\CustomerStoreMembershipService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CheckoutOrderService
{
    public function __construct(
        private Connection $db,
        private PublicIdFactory $ids,
        private PaymentProviderRegistry $payments,
        private EventBusInterface $events,
        private DomainEventFactory $eventFactory,
        private PromotionEngine $promotions,
        private PromotionRedemptionRecorder $promotionRedemptions,
        private CheckoutLayoutService $checkoutLayout,
        private B2bCommerceService $b2b,
        private GiftCardService $giftCards,
        private LoyaltyService $loyalty,
        private CustomerStoreMembershipService $memberships,
    ) {}

    /** @return array{public_id:string,order_number:string,total_minor:int,currency:string} */
    public function place(StorefrontContext $context, int $cartId, array $input, string $idempotencyKey, ?int $customerId = null): array
    {
        $layout = $this->checkoutLayout->active($context->storeId);
        $requirements = $layout['requirements'];
        $name = trim((string)($input['name'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $email = mb_strtolower(trim((string)($input['email'] ?? '')));
        $companyName = !empty($requirements['show_company']) ? trim((string)($input['company_name'] ?? '')) : '';
        $companyTaxId = !empty($requirements['show_company']) ? trim((string)($input['company_tax_id'] ?? '')) : '';
        $customerComment = !empty($requirements['show_comment']) ? trim((string)($input['customer_comment'] ?? '')) : '';
        $providerCode = trim((string)($input['carrier'] ?? ''));
        $paymentCode = trim((string)($input['payment_method'] ?? 'cash_on_delivery'));
        if (mb_strlen($name) > 190) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.imia_zanadto_dovhe'));
        if (!empty($requirements['require_name']) && $name === '') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerregistrationservice.vkazhit_imia'));
        if (mb_strlen($phone) > 32) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.telefon_zanadto_dovhyi'));
        if (!empty($requirements['require_phone']) && $phone === '') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.vkazhit_telefon'));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.customer.application.customerinquiryservice.nekorektnyi_email'));
        if (!empty($requirements['require_email']) && $email === '') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.vkazhit_email'));
        if ($phone === '' && $email === '') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.vkazhit_telefon_abo_email_dlia_zviazku'));
        if (mb_strlen($companyName) > 190 || mb_strlen($companyTaxId) > 64) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.nekorektni_rekvizyty_kompanii'));
        if (mb_strlen($customerComment) > 2000) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.komentar_zanadto_dovhyi'));
        $payment = $this->payments->require($paymentCode)->method();
        if ($paymentCode==='b2b_invoice' && $this->b2b->membership($context->storeId,$customerId)===null) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.b2b_oplata_dostupna_lyshe_pidtverdzhenomu_spivrobitn'));
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 190) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.nekorektnyi_kliuch_oformlennia'));
        $purchaseOrderNumber=mb_substr(trim((string)($input['purchase_order_number']??'')),0,128);

        $result = $this->db->transactional(function(Connection $db) use($context,$cartId,$input,$idempotencyKey,$name,$phone,$email,$providerCode,$payment,$customerId,$companyName,$companyTaxId,$customerComment,$purchaseOrderNumber): array {
            $existing = $db->fetchAssociative("SELECT public_id,order_number,total_minor,currency FROM mc_sales_order WHERE checkout_idempotency_key=? AND store_id=? LIMIT 1", [$idempotencyKey, $context->storeId]);
            if (is_array($existing)) return ['public_id'=>Uuid::fromBinary((string)$existing['public_id'])->toRfc4122(),'order_number'=>(string)$existing['order_number'],'total_minor'=>(int)$existing['total_minor'],'currency'=>(string)$existing['currency']];

            if ($customerId !== null) {
                $activeCustomer = $db->fetchOne("SELECT id FROM mc_customer WHERE id=? AND status='active' LIMIT 1", [$customerId]);
                if ((int) $activeCustomer !== $customerId) {
                    throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.oblikovyi_zapys_pokuptsia_bilshe_ne_aktyvnyi'));
                }
                $this->memberships->ensure($context->storeId, $customerId);
            }
            $cart = $db->fetchAssociative("SELECT id,store_id,currency,status FROM mc_cart WHERE id=? AND store_id=? FOR UPDATE", [$cartId,$context->storeId]);
            if (!is_array($cart) || $cart['status'] !== 'active') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.koshyk_bilshe_ne_aktyvnyi'));
            $rows = $db->fetchAllAssociative("SELECT ci.id cart_item_id,ci.variant_id,ci.quantity,ci.unit_code,ci.unit_price_minor,v.sku,v.product_id,COALESCE(pt.name,ptd.name,v.sku) AS name,p.product_type,v.allow_backorder,COALESCE(ppp.mode,'auto') AS purchase_mode,vii.inventory_item_id,vii.required_quantity,sl.location_id,sl.stocked_quantity,sl.reserved_quantity,sl.safety_stock FROM mc_cart_item ci JOIN mc_product_variant v ON v.id=ci.variant_id JOIN mc_product p ON p.id=v.product_id JOIN mc_store st ON st.id=? LEFT JOIN mc_product_translation pt ON pt.product_id=p.id AND pt.store_id=st.id AND pt.locale=? LEFT JOIN mc_product_translation ptd ON ptd.product_id=p.id AND ptd.store_id=st.id AND ptd.locale=st.default_locale LEFT JOIN mc_product_purchase_policy ppp ON ppp.product_id=p.id LEFT JOIN mc_variant_inventory_item vii ON vii.variant_id=v.id LEFT JOIN mc_stock_level sl ON sl.inventory_item_id=vii.inventory_item_id WHERE ci.cart_id=? ORDER BY ci.id ASC FOR UPDATE", [$context->storeId,$context->locale,$cartId]);
            if ($rows === []) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.koshyk_porozhnii'));
            foreach($rows as &$priceRow){
                $retail=$db->fetchOne("SELECT px.amount_minor FROM mc_price px WHERE px.variant_id=? AND px.store_id=? AND (px.market_id=? OR px.market_id IS NULL) AND px.currency=? AND px.customer_group='default' AND px.price_list_id IS NULL AND px.min_quantity<=? AND (px.max_quantity IS NULL OR px.max_quantity>=?) AND (px.starts_at IS NULL OR px.starts_at<=UTC_TIMESTAMP(6)) AND (px.ends_at IS NULL OR px.ends_at>UTC_TIMESTAMP(6)) ORDER BY (px.market_id IS NOT NULL) DESC,px.priority ASC,px.id DESC LIMIT 1",[(int)$priceRow['variant_id'],$context->storeId,$context->marketId,$context->currency,(string)$priceRow['quantity'],(string)$priceRow['quantity']]);
                if($retail===false){throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('checkout.error.item_price_unavailable',['name'=>(string)$priceRow['name'],'currency'=>$context->currency]));} $base=(int)$retail; // never charge a stored price that may belong to another currency or an expired price
                $resolved=$this->b2b->priceFor($context->storeId,$customerId,(int)$priceRow['variant_id'],(string)$priceRow['quantity'],$base,$context->currency);
                $priceRow['unit_price_minor']=$resolved; $db->update('mc_cart_item',['unit_price_minor'=>$resolved,'updated_at'=>$this->now()],['id'=>(int)$priceRow['cart_item_id'],'cart_id'=>$cartId]);
            } unset($priceRow);
            $requiresShipping=false; $hasDigital=false; foreach($rows as $r){ if ((string)$r['product_type'] === 'digital') { $hasDigital=true; } else { $requiresShipping=true; } }
            if ($hasDigital && $customerId === null) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.dlia_tsyfrovykh_tovariv_uviidit_abo_stvorit_oblikovy'));
            if ($hasDigital) { foreach($rows as $r){ if ((string)$r['product_type'] !== 'digital') continue; $assetCount=(int)$db->fetchOne("SELECT COUNT(*) FROM mc_product_digital_asset WHERE product_id=? AND status='active'",[(int)$r['product_id']]); if($assetCount<1) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.tsyfrovyi_tovar').(string)$r['name'].\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.tymchasovo_nedostupnyi_fail_dlia_vydachi_shche_ne_na')); } }
            if ($requiresShipping && $providerCode === '') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.oberit_sluzhbu_dostavky'));
            if ($requiresShipping && trim((string)($input['point_id'] ?? '')) === '' && trim((string)($input['delivery_manual'] ?? '')) === '') throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.oberit_viddilennia_abo_vkazhit_dostavku_vruchnu'));
            if (!$requiresShipping) $providerCode='digital';
            if (!$requiresShipping && $payment->requiresShipping) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.tsei_sposib_oplaty_dostupnyi_lyshe_dlia_fizychnoi_do'));

            $subtotal=0; foreach($rows as $r){$q=Quantity::fromString((string)$r['quantity']); $subtotal += intdiv(((int)$r['unit_price_minor']*$q->micros)+500000,1000000);}
            $promotionResult=$this->promotions->calculateForCart($context->storeId,$cartId,trim((string)($input['coupon_code']??'')) ?: null,$customerId,$email,true);
            if ($promotionResult->couponMessage !== null && trim((string)($input['coupon_code']??'')) !== '') throw new \DomainException($promotionResult->couponMessage);
            $discount=$promotionResult->discountMinor; $shipping=0; $tax=0; $total=max(0,$subtotal-$discount+$shipping+$tax);
            $giftCode=trim((string)($input['gift_card_code']??''));
            $giftPreview=$giftCode!==''?$this->giftCards->preview($context->storeId,$giftCode,$context->currency,$total):null;
            if($giftCode!=='' && $giftPreview===null) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.podarunkova_kartka_nediisna_prostrochena_abo_maie_in'));
            $requestedPoints=max(0,(int)($input['loyalty_points']??0));
            if($requestedPoints>0 && $customerId===null) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.dlia_vykorystannia_baliv_potribno_uviity_v_oblikovyi'));
            $benefitPreview=(int)($giftPreview['amount_minor']??0);
            $b2bTerms=$this->b2b->checkoutTerms($context->storeId,$customerId,max(0,$total-$benefitPreview));
            if ($payment->code==='b2b_invoice' && $b2bTerms===null) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.b2b_umovy_bilshe_nedostupni'));
            $b2bApproval=$b2bTerms['approval_status']??null;
            $paymentTerms=$b2bTerms===null?null:(int)$b2bTerms['payment_terms_days'];
            $dueAt=$paymentTerms!==null&&$paymentTerms>0?(new DateTimeImmutable('+'.$paymentTerms.' days',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'):null;
            $now=$this->now(); $public=$this->ids->generate(); $storeCode=(string)($db->fetchOne('SELECT code FROM mc_store WHERE id=?',[$context->storeId]) ?: 'MC'); $orderNumber=$this->orderNumber($db,$context->storeId,$storeCode);
            $db->insert('mc_sales_order',['public_id'=>$public->toBinary(),'store_id'=>$context->storeId,'customer_id'=>$customerId,'b2b_company_id'=>$b2bTerms===null?null:(int)$b2bTerms['company_id'],'b2b_approval_status'=>$b2bApproval,'purchase_order_number'=>$purchaseOrderNumber!==''?$purchaseOrderNumber:null,'payment_terms_days'=>$paymentTerms,'due_at'=>$dueAt,'order_number'=>$orderNumber,'checkout_idempotency_key'=>$idempotencyKey,'status'=>$b2bApproval==='pending'?'pending_approval':'placed','payment_status'=>'pending','fulfillment_status'=>'unfulfilled','currency'=>$context->currency,'prices_include_tax'=>1,'subtotal_minor'=>$subtotal,'discount_minor'=>$discount,'shipping_minor'=>$shipping,'tax_minor'=>$tax,'tax_country_code'=>$context->countryCode,'tax_calculation_mode'=>'included','total_minor'=>$total,'customer_email'=>$email!==''?$email:null,'customer_email_normalized'=>$email!==''?$email:null,'customer_phone'=>$phone,'customer_name'=>$name,'customer_comment'=>$customerComment!==''?$customerComment:null,'company_name'=>$companyName!==''?$companyName:($b2bTerms!==null?(string)$b2bTerms['name']:null),'company_tax_id'=>$companyTaxId!==''?$companyTaxId:($b2bTerms!==null?(string)($b2bTerms['tax_id']??''):null),'locale'=>$context->locale,'created_at'=>$now,'updated_at'=>$now]);
            $orderId=(int)$db->lastInsertId();
            $gift=$giftCode!==''?$this->giftCards->redeem($db,$context->storeId,$giftCode,$context->currency,$total,$orderId):null;
            $giftMinor=(int)($gift['amount_minor']??0);
            $loyalty=$customerId!==null?$this->loyalty->redeem($db,$context->storeId,$customerId,$requestedPoints,max(0,$total-$giftMinor),$orderId):['points'=>0,'amount_minor'=>0];
            $loyaltyMinor=(int)$loyalty['amount_minor'];
            $total=max(0,$total-$giftMinor-$loyaltyMinor);
            $db->update('mc_sales_order',['gift_card_minor'=>$giftMinor,'gift_card_last4'=>$gift['last4']??null,'loyalty_minor'=>$loyaltyMinor,'loyalty_points_spent'=>(int)$loyalty['points'],'total_minor'=>$total,'updated_at'=>$now],['id'=>$orderId]);
            foreach($rows as $r){
                $q=Quantity::fromString((string)$r['quantity']); $line=intdiv(((int)$r['unit_price_minor']*$q->micros)+500000,1000000);
                $db->insert('mc_sales_order_item',['order_id'=>$orderId,'product_id'=>(int)$r['product_id'],'variant_id'=>(int)$r['variant_id'],'sku'=>(string)$r['sku'],'name'=>(string)$r['name'],'quantity'=>$q->toDatabase(),'unit_code'=>(string)$r['unit_code'],'unit_price_minor'=>(int)$r['unit_price_minor'],'unit_price_net_minor'=>(int)$r['unit_price_minor'],'unit_price_gross_minor'=>(int)$r['unit_price_minor'],'line_total_minor'=>$line,'tax_minor'=>0,'tax_rate_bps'=>0,'tax_class_code'=>null,'snapshot'=>json_encode(['sku'=>(string)$r['sku'],'name'=>(string)$r['name'],'unit_code'=>(string)$r['unit_code'],'purchase_mode'=>(string)$r['purchase_mode']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
                $orderItemId=(int)$db->lastInsertId();
                if ((string)$r['product_type'] === 'digital') {
                    $assets=$db->fetchAllAssociative("SELECT id,max_downloads,access_days FROM mc_product_digital_asset WHERE product_id=? AND status='active' ORDER BY id ASC",[(int)$r['product_id']]);
                    foreach($assets as $asset){
                        $db->insert('mc_digital_entitlement',[
                            'public_id'=>$this->ids->binary(),'store_id'=>$context->storeId,'order_id'=>$orderId,'order_item_id'=>$orderItemId,
                            'customer_id'=>$customerId,'asset_id'=>(int)$asset['id'],'status'=>'pending','max_downloads'=>(int)$asset['max_downloads'],
                            'download_count'=>0,'access_days'=>$asset['access_days']===null?null:(int)$asset['access_days'],'activated_at'=>null,'expires_at'=>null,
                            'last_downloaded_at'=>null,'created_at'=>$now,'updated_at'=>$now,
                        ]);
                    }
                } else {
                    $allowBackorder = (bool)($r['allow_backorder'] ?? false) || in_array((string)$r['purchase_mode'], ['backorder','preorder'], true);
                    if ($r['inventory_item_id'] === null || $r['location_id'] === null) {
                        if (!$allowBackorder) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.dlia_tovaru_ne_nalashtovano_skladskyi_oblik'));
                        continue;
                    }
                    $required=Quantity::fromString((string)$r['required_quantity']); $reserve=Quantity::fromMicros(intdiv($q->micros*$required->micros, Quantity::FACTOR));
                    $changed=$db->executeStatement('UPDATE mc_stock_level SET reserved_quantity=reserved_quantity+?,row_version=row_version+1,updated_at=? WHERE inventory_item_id=? AND location_id=? AND (stocked_quantity-reserved_quantity-safety_stock)>=?',[$reserve->toDatabase(),$now,(int)$r['inventory_item_id'],(int)$r['location_id'],$reserve->toDatabase()]);
                    if($changed!==1) {
                        if (!$allowBackorder) throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.checkoutorderservice.nedostatno_tovaru_v_naiavnosti'));
                        continue;
                    }
                    $db->insert('mc_inventory_reservation',['public_id'=>$this->ids->binary(),'inventory_item_id'=>(int)$r['inventory_item_id'],'location_id'=>(int)$r['location_id'],'cart_id'=>$cartId,'order_id'=>$orderId,'idempotency_key'=>'order:'.$orderId.':'.(int)$r['inventory_item_id'],'quantity'=>$reserve->toDatabase(),'status'=>'active','expires_at'=>(new DateTimeImmutable($payment->code==='monobank'?'+65 minutes':($payment->code==='b2b_invoice'?'+7 days':'+2 days'),new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),'created_at'=>$now,'released_at'=>null,'committed_at'=>null]);
                }
            }
            $destination=['country'=>$context->countryCode,'provider'=>$providerCode,'city_id'=>(string)($input['city_id']??''),'city'=>(string)($input['city_name']??''),'point_id'=>(string)($input['point_id']??''),'point'=>(string)($input['point_name']??''),'manual'=>(string)($input['delivery_manual']??'')];
            if ($requiresShipping) { $db->insert('mc_fulfillment',['public_id'=>$this->ids->binary(),'order_id'=>$orderId,'provider_code'=>$providerCode,'service_type'=>(string)($input['service_type']??'pickup_point'),'status'=>'pending','tracking_number'=>null,'destination_snapshot'=>json_encode($destination,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'provider_snapshot'=>null,'created_at'=>$now,'updated_at'=>$now]); } else { $db->update('mc_sales_order',['fulfillment_status'=>'not_required'],['id'=>$orderId]); }
            $db->insert('mc_payment',['public_id'=>$this->ids->binary(),'order_id'=>$orderId,'provider_code'=>$payment->code,'provider_reference'=>null,'status'=>'pending','amount_minor'=>$total,'currency'=>$context->currency,'idempotency_key'=>'payment:'.$idempotencyKey,'metadata'=>json_encode(['online'=>$payment->online],JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now]);
            $this->promotionRedemptions->record($orderId,$promotionResult,$customerId,$email);
            $db->insert('mc_order_event',['order_id'=>$orderId,'sequence_no'=>1,'event_type'=>'order.placed','payload'=>json_encode(['payment_method'=>$payment->code,'carrier'=>$providerCode],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'actor_type'=>'customer','actor_subject'=>$email!==''?$email:$phone,'created_at'=>$now]);
            $this->events->publish($this->eventFactory->create(
                EventNames::ORDER_PLACED,
                'order',
                $public->toRfc4122(),
                [
                    'order_number' => $orderNumber,
                    'store_id' => $context->storeId,
                    'market_id' => $context->marketId,
                    'currency' => $context->currency,
                    'total_minor' => $total,
                ],
                ['source' => 'checkout'],
            ));
            $db->update('mc_cart',['status'=>'converted','updated_at'=>$now],['id'=>$cartId]);
            return ['public_id'=>$public->toRfc4122(),'order_number'=>$orderNumber,'total_minor'=>$total,'currency'=>$context->currency];
        });
        return $result;
    }

    /**
     * PREFIX-YYMMDD-XXXXXX with a random, unambiguous suffix (32^6 ≈ 1 billion per store and day).
     * The first bytes of a UUIDv7 are a millisecond clock and repeat for ~65 s, so they must not be used:
     * two orders within a minute would collide on uq_sales_order_number.
     */
    private function orderNumber(Connection $db,int $storeId,string $storeCode): string
    {
        $prefix=substr(strtoupper((string)(preg_replace('/[^A-Z0-9]+/i','',trim($storeCode)) ?: 'MC')),0,8);
        $alphabet='23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        for($attempt=0;$attempt<8;$attempt++){
            $suffix='';
            foreach(str_split(random_bytes(6)) as $byte){$suffix.=$alphabet[ord($byte)%32];}
            $number=$prefix.'-'.gmdate('ymd').'-'.$suffix;
            if(!$db->fetchOne('SELECT 1 FROM mc_sales_order WHERE store_id=? AND order_number=? LIMIT 1',[$storeId,$number]))return $number;
        }
        throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('common.error.operation_failed'));
    }
    private function now(): string { return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
}
