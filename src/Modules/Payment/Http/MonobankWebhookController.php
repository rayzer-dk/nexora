<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Http;

use Commerce\Modules\Payment\Application\PaymentLifecycleService;
use Commerce\Modules\Payment\Provider\Monobank\MonobankWebhookVerifier;
use Commerce\Modules\Security\Webhook\WebhookReplayGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MonobankWebhookController
{
    public function __construct(
        private MonobankWebhookVerifier $verifier,
        private PaymentLifecycleService $lifecycle,
        private WebhookReplayGuard $replays,
    ) {}

    #[Route('/webhooks/payments/monobank', name:'payment_webhook_monobank', methods:['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $raw=$request->getContent(); $signature=(string)$request->headers->get('X-Sign','');
        if($signature==='' || !$this->verifier->verify($raw,$signature)) return new JsonResponse(['ok'=>false],401);
        try { $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR); } catch(\Throwable){ return new JsonResponse(['ok'=>false],400); }
        if(!is_array($data)) return new JsonResponse(['ok'=>false],400);
        $invoice=trim((string)($data['invoiceId']??'')); $status=trim((string)($data['status']??''));
        if($invoice==='' || $status==='') return new JsonResponse(['ok'=>false],400);
        $fingerprint=hash('sha256',$raw . "\0" . $signature);
        if(!$this->replays->claim('monobank',$fingerprint)) return new JsonResponse(['ok'=>true,'duplicate'=>true]);
        try {
            $this->lifecycle->applyProviderStatus('monobank',$invoice,$status,isset($data['modifiedDate'])?(string)$data['modifiedDate']:null,(int)($data['amount']??0),((int)($data['ccy']??980))===980?'UAH':(string)($data['ccy']??''),isset($data['failureReason'])?(string)$data['failureReason']:null,$data);
        } catch(\RuntimeException $e) {
            $this->replays->release('monobank',$fingerprint);
            // Unknown invoice should be retried only if it may be a race; report 409 rather than accepting silently.
            return new JsonResponse(['ok'=>false,'error'=>'payment_state_rejected'],409);
        } catch(\Throwable $e) {
            $this->replays->release('monobank',$fingerprint);
            throw $e;
        }
        return new JsonResponse(['ok'=>true]);
    }
}
