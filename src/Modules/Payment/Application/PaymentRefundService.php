<?php

declare(strict_types=1);

namespace Commerce\Modules\Payment\Application;

use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Payment\Contract\OnlinePaymentProviderInterface;
use Commerce\Modules\Payment\Domain\RefundResult;
use Commerce\Modules\Rewards\Application\GiftCardService;
use Commerce\Modules\Rewards\Application\LoyaltyService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class PaymentRefundService
{
    public function __construct(
        private Connection $db,
        private PaymentProviderRegistry $providers,
        private PublicIdFactory $ids,
        private GiftCardService $giftCards,
        private LoyaltyService $loyalty,
    ) {}

    public function refundFull(string $orderPublicId, string $actor): void
    {
        $binary = $this->uuidBinary($orderPublicId);
        $row = $this->db->fetchAssociative(
            'SELECT p.amount_minor,p.refunded_minor
             FROM mc_sales_order o
             JOIN mc_payment p ON p.order_id=o.id
             WHERE o.public_id=?
             ORDER BY p.id DESC
             LIMIT 1',
            [$binary],
        );
        if (!is_array($row)) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.zamovlennia_abo_platizh_ne_znaideneno'));
        }
        $pending = (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(r.amount_minor),0)
             FROM mc_payment_refund r
             JOIN mc_payment p ON p.id=r.payment_id
             JOIN mc_sales_order o ON o.id=p.order_id
             WHERE o.public_id=? AND r.status IN ('pending','processing')",
            [$binary],
        );
        $remaining = (int) $row['amount_minor'] - (int) $row['refunded_minor'] - $pending;
        if ($remaining <= 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.nemaie_sumy_dostupnoi_dlia_povernennia'));
        }
        $this->refundAmount($orderPublicId, $remaining, $actor);
    }

    public function refundAmount(string $orderPublicId, int $amountMinor, string $actor): void
    {
        if ($amountMinor <= 0) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.admin.http.orderadmincontroller.suma_povernennia_maie_buty_bilshoiu_za_nul'));
        }

        $binary = $this->uuidBinary($orderPublicId);
        $prepared = $this->db->transactional(function (Connection $db) use ($binary, $amountMinor, $actor): array {
            $row = $db->fetchAssociative(
                'SELECT o.id order_id,o.order_number,p.id payment_id,p.provider_code,p.provider_reference,
                        p.status payment_status,p.amount_minor,p.refunded_minor
                 FROM mc_sales_order o
                 JOIN mc_payment p ON p.order_id=o.id
                 WHERE o.public_id=?
                 ORDER BY p.id DESC
                 LIMIT 1
                 FOR UPDATE',
                [$binary],
            );
            if (!is_array($row)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.zamovlennia_abo_platizh_ne_znaideno'));
            }
            if (!in_array((string) $row['payment_status'], ['paid', 'partially_refunded'], true)) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.povernennia_dostupne_lyshe_dlia_pidtverdzhenoi_oplat'));
            }

            $pending = (int) $db->fetchOne(
                "SELECT COALESCE(SUM(amount_minor),0)
                 FROM mc_payment_refund
                 WHERE payment_id=? AND status IN ('pending','processing')",
                [(int) $row['payment_id']],
            );
            $available = (int) $row['amount_minor'] - (int) $row['refunded_minor'] - $pending;
            if ($available <= 0) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.oplatu_vzhe_poverneno_abo_povernennia_vzhe_obrobliai'));
            }
            if ($amountMinor > $available) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.suma_povernennia_perevyshchuie_dostupnyi_zalyshok'));
            }

            $provider = $this->providers->require((string) $row['provider_code']);
            if (!$provider instanceof OnlinePaymentProviderInterface) {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.provaider_ne_pidtrymuie_avtomatychne_povernennia'));
            }
            if (trim((string) $row['provider_reference']) === '') {
                throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.platizh_ne_maie_identyfikatora_provaidera'));
            }

            $public = $this->ids->generate();
            $key = 'refund:' . $public->toRfc4122();
            $now = $this->now();
            $db->insert('mc_payment_refund', [
                'public_id' => $public->toBinary(),
                'payment_id' => (int) $row['payment_id'],
                'provider_reference' => null,
                'idempotency_key' => $key,
                'amount_minor' => $amountMinor,
                'status' => 'pending',
                'provider_payload' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $refundId = (int) $db->lastInsertId();
            $this->appendEvent($db, (int) $row['order_id'], 'payment.refund_requested', [
                'amount_minor' => $amountMinor,
                'refund_id' => $refundId,
            ], 'admin', $actor);

            return [
                'refund_id' => $refundId,
                'order_id' => (int) $row['order_id'],
                'payment_id' => (int) $row['payment_id'],
                'payment_amount' => (int) $row['amount_minor'],
                'provider' => $provider,
                'reference' => (string) $row['provider_reference'],
                'amount' => $amountMinor,
                'key' => $key,
                'actor' => $actor,
            ];
        });

        try {
            /** @var RefundResult $result */
            $result = $prepared['provider']->refund($prepared['reference'], $prepared['amount'], $prepared['key']);
            $status = $this->normalizeProviderRefundStatus($result->status);
            $this->recordProviderResult(
                (int) $prepared['refund_id'],
                (int) $prepared['order_id'],
                (int) $prepared['payment_id'],
                (int) $prepared['payment_amount'],
                (int) $prepared['amount'],
                $status,
                $result->providerPayload,
                (string) $prepared['actor'],
            );
        } catch (\Throwable $e) {
            $this->db->update('mc_payment_refund', [
                'status' => 'failed',
                'provider_payload' => json_encode(['error' => mb_substr($e->getMessage(), 0, 500)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => $this->now(),
            ], ['id' => (int) $prepared['refund_id']]);
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.provaider_ne_pryiniav_zapyt_na_povernennia'));
        }
    }

    /** @param array<string,mixed> $providerPayload */
    private function recordProviderResult(
        int $refundId,
        int $orderId,
        int $paymentId,
        int $paymentAmount,
        int $refundAmount,
        string $status,
        array $providerPayload,
        string $actor,
    ): void {
        $this->db->transactional(function (Connection $db) use ($refundId, $orderId, $paymentId, $paymentAmount, $refundAmount, $status, $providerPayload, $actor): void {
            $refund = $db->fetchAssociative('SELECT * FROM mc_payment_refund WHERE id=? FOR UPDATE', [$refundId]);
            if (!is_array($refund)) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.zapys_povernennia_ne_znaideno'));
            }

            $db->update('mc_payment_refund', [
                'status' => $status,
                'provider_payload' => json_encode($providerPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $this->now(),
            ], ['id' => $refundId]);

            if ($status !== 'succeeded') {
                $this->appendEvent($db, $orderId, 'payment.refund_processing', [
                    'amount_minor' => $refundAmount,
                    'refund_id' => $refundId,
                    'status' => $status,
                ], 'provider', 'payment_provider');
                return;
            }

            $payment = $db->fetchAssociative('SELECT * FROM mc_payment WHERE id=? FOR UPDATE', [$paymentId]);
            if (!is_array($payment)) {
                throw new \RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.payment.application.paymentrefundservice.platizh_ne_znaideno'));
            }
            $alreadyFinalized = (int) $payment['refunded_minor'];
            $newRefunded = min($paymentAmount, $alreadyFinalized + $refundAmount);
            $full = $newRefunded >= $paymentAmount;
            $paymentStatus = $full ? 'refunded' : 'partially_refunded';
            $db->update('mc_payment', [
                'status' => $paymentStatus,
                'refunded_minor' => $newRefunded,
                'updated_at' => $this->now(),
            ], ['id' => $paymentId]);
            $now = $this->now();
            $db->update('mc_sales_order', [
                'status' => $full ? 'refunded' : 'confirmed',
                'payment_status' => $paymentStatus,
                'updated_at' => $now,
            ], ['id' => $orderId]);
            if ($full) {
                $db->executeStatement("UPDATE mc_digital_entitlement SET status='revoked',updated_at=? WHERE order_id=? AND status IN ('pending','active')", [$now, $orderId]);
                $this->giftCards->restoreForOrder($db,$orderId,'refund');
                $this->loyalty->reverseEarnForOrder($db,$orderId);
                $this->loyalty->restoreSpendForOrder($db,$orderId,'refund');
            }
            $this->appendEvent($db, $orderId, $full ? 'payment.refunded' : 'payment.partially_refunded', [
                'amount_minor' => $refundAmount,
                'refunded_total_minor' => $newRefunded,
                'refund_id' => $refundId,
            ], 'admin', $actor);
        });
    }

    private function normalizeProviderRefundStatus(string $status): string
    {
        return match (mb_strtolower(trim($status))) {
            'success', 'succeeded', 'completed', 'refunded' => 'succeeded',
            'failed', 'failure', 'rejected', 'cancelled', 'canceled' => 'failed',
            default => 'processing',
        };
    }

    private function uuidBinary(string $orderPublicId): string
    {
        try {
            return Uuid::fromString($orderPublicId)->toBinary();
        } catch (\Throwable) {
            throw new \DomainException(\Commerce\Core\I18n\CanonicalUiText::get('php.modules.order.application.ordermanagementservice.nekorektnyi_identyfikator_zamovlennia'));
        }
    }

    /** @param array<string,mixed> $payload */
    private function appendEvent(Connection $db, int $orderId, string $type, array $payload, string $actorType, string $actor): void
    {
        $next = (int) $db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM mc_order_event WHERE order_id=?', [$orderId]);
        $db->insert('mc_order_event', [
            'order_id' => $orderId,
            'sequence_no' => $next,
            'event_type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actor_type' => $actorType,
            'actor_subject' => $actor,
            'created_at' => $this->now(),
        ]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
