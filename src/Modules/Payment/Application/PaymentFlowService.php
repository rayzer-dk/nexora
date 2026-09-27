<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\OnlinePaymentRequest;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class PaymentFlowService
{
    public function __construct(
        private Connection $db,
        private PaymentProviderRegistry $providers,
        private PaymentLifecycleService $lifecycle,
        private string $publicBaseUrl,
    ) {}

    /** @return array{redirect_url:?string,status:string} */
    public function afterOrderPlaced(string $orderPublicId): array
    {
        $binary = Uuid::fromString($orderPublicId)->toBinary();
        $row = $this->db->fetchAssociative(
            'SELECT o.id,o.public_id,o.order_number,o.total_minor,o.currency,p.id payment_id,p.provider_code,p.provider_reference,p.status payment_status'
            . ' FROM mc_sales_order o JOIN mc_payment p ON p.order_id=o.id WHERE o.public_id=? ORDER BY p.id DESC LIMIT 1',
            [$binary],
        );
        if (!is_array($row)) {
            throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentflowservice.platizh_dlia_zamovlennia_ne_znaideno'));
        }

        $provider = $this->providers->require((string)$row['provider_code']);
        $code = $provider->method()->code;

        if ($code === 'b2b_invoice') {
            $approval=(string)($this->db->fetchOne('SELECT COALESCE(b2b_approval_status,\'approved\') FROM mc_sales_order WHERE id=?',[(int)$row['id']]) ?: 'approved');
            if($approval==='pending'){ $this->db->update('mc_sales_order',['status'=>'pending_approval','updated_at'=>$this->now()],['id'=>(int)$row['id']]); return ['redirect_url'=>null,'status'=>'pending_approval']; }
            $this->lifecycle->confirmDeferredPayment($orderPublicId,'b2b_invoice');
            return ['redirect_url'=>null,'status'=>'confirmed'];
        }
        if ($code === 'cash_on_delivery') {
            $this->lifecycle->confirmCashOnDelivery($orderPublicId);
            return ['redirect_url'=>null,'status'=>'confirmed'];
        }
        if (!$provider instanceof OnlinePaymentProviderInterface) {
            $this->db->update('mc_sales_order', ['status'=>'awaiting_payment','updated_at'=>$this->now()], ['id'=>(int)$row['id']]);
            return ['redirect_url'=>null,'status'=>'awaiting_payment'];
        }

        if ((string)$row['provider_reference'] !== '') {
            $meta = $this->decodeJson($this->db->fetchOne('SELECT metadata FROM mc_payment WHERE id=?', [(int)$row['payment_id']]));
            return ['redirect_url'=>isset($meta['redirect_url'])?(string)$meta['redirect_url']:null,'status'=>(string)$row['payment_status']];
        }

        $base = rtrim($this->publicBaseUrl, '/');
        $session = $provider->createPayment(new OnlinePaymentRequest(
            orderPublicId: $orderPublicId,
            orderNumber: (string)$row['order_number'],
            amountMinor: (int)$row['total_minor'],
            currency: (string)$row['currency'],
            returnUrl: $base.'/payment/return/'.$orderPublicId,
            webhookUrl: $base.'/webhooks/payments/monobank',
        ));

        $now = $this->now();
        $metadata = array_merge($session->metadata, ['redirect_url'=>$session->redirectUrl,'app_url'=>$session->appUrl]);
        $this->db->transactional(function(Connection $db) use($row,$session,$metadata,$now): void {
            $db->update('mc_payment', [
                'provider_reference'=>$session->providerReference,
                'status'=>'awaiting_payment',
                'metadata'=>json_encode($metadata, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                'updated_at'=>$now,
            ], ['id'=>(int)$row['payment_id']]);
            $db->update('mc_sales_order', ['status'=>'awaiting_payment','updated_at'=>$now], ['id'=>(int)$row['id']]);
            $this->appendEvent($db, (int)$row['id'], 'payment.invoice_created', ['provider_reference'=>$session->providerReference], 'system', 'payment');
        });

        return ['redirect_url'=>$session->redirectUrl,'status'=>'awaiting_payment'];
    }

    private function appendEvent(Connection $db, int $orderId, string $type, array $payload, string $actorType, string $actor): void
    {
        $next = (int)$db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM mc_order_event WHERE order_id=?', [$orderId]);
        $db->insert('mc_order_event', [
            'order_id'=>$orderId,'sequence_no'=>$next,'event_type'=>$type,
            'payload'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'actor_type'=>$actorType,'actor_subject'=>$actor,'created_at'=>$this->now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $json): array
    {
        if (!is_string($json) || $json==='') return [];
        try { $value=json_decode($json,true,512,JSON_THROW_ON_ERROR); return is_array($value)?$value:[]; } catch (\Throwable) { return []; }
    }

    private function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
}
