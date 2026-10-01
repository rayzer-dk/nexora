<?php

declare(strict_types=1);

namespace Commerce\Modules\Order\Application;

use Commerce\Modules\Storefront\Infrastructure\StorefrontMoneyFormatter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Data for the "order accepted" page: number, totals, chosen delivery and payment. No personal data beyond what the buyer typed. */
final readonly class OrderConfirmationQuery
{
    public function __construct(private Connection $db, private OrderMethodPresenter $methods, private StorefrontMoneyFormatter $money)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $publicId, int $storeId, string $locale): ?array
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }
        $order = $this->db->fetchAssociative('SELECT id,order_number,total_minor,currency,customer_email,status FROM mc_sales_order WHERE public_id=? AND store_id=?', [Uuid::fromString($publicId)->toBinary(), $storeId]);
        if (!is_array($order)) {
            return null;
        }
        $orderId = (int) $order['id'];
        $fulfillment = $this->db->fetchAssociative('SELECT provider_code,destination_snapshot FROM mc_fulfillment WHERE order_id=? ORDER BY id DESC LIMIT 1', [$orderId]);
        $payment = $this->db->fetchAssociative('SELECT provider_code FROM mc_payment WHERE order_id=? ORDER BY id DESC LIMIT 1', [$orderId]);
        $destination = is_array($fulfillment) ? (json_decode((string) $fulfillment['destination_snapshot'], true) ?: []) : [];
        $items = $this->db->fetchAllAssociative('SELECT name,quantity,unit_code,line_total_minor FROM mc_sales_order_item WHERE order_id=? ORDER BY id', [$orderId]);
        $currency = (string) $order['currency'];
        $delivery = null;
        if (is_array($fulfillment)) {
            $code = (string) $fulfillment['provider_code'];
            $delivery = [
                'code' => $code,
                'label' => $this->methods->delivery($code, $locale),
                'summary' => $this->methods->deliverySummary($code, is_array($destination) ? $destination : [], $locale),
                'pickup' => $code === 'self_pickup' ? ['name' => (string) ($destination['point'] ?? ''), 'address' => (string) ($destination['address'] ?? ''), 'hours' => (string) ($destination['working_hours'] ?? ''), 'phone' => (string) ($destination['phone'] ?? '')] : null,
            ];
        }
        $paymentCode = is_array($payment) ? (string) $payment['provider_code'] : '';

        return [
            'order_number' => (string) $order['order_number'],
            'total' => $this->money->format((int) $order['total_minor'], $currency, $locale),
            'email' => (string) ($order['customer_email'] ?? ''),
            'delivery' => $delivery,
            'payment' => $paymentCode !== '' ? ['code' => $paymentCode, 'label' => $this->methods->payment($paymentCode, $locale), 'pay_on_receipt' => $paymentCode === 'cash_on_delivery'] : null,
            'items' => array_map(fn (array $i): array => ['name' => (string) $i['name'], 'quantity' => rtrim(rtrim((string) $i['quantity'], '0'), '.'), 'unit' => (string) $i['unit_code'], 'total' => $this->money->format((int) $i['line_total_minor'], $currency, $locale)], $items),
        ];
    }
}
