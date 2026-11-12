<?php

declare(strict_types=1);

namespace Commerce\Modules\Order\Application;

use Commerce\Modules\Storefront\Contract\MoneyFormatterInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Data for the "order accepted" page: number, totals, chosen delivery and payment. No personal data beyond what the buyer typed. */
final readonly class OrderConfirmationQuery
{
    public function __construct(private Connection $db, private OrderMethodPresenter $methods, private MoneyFormatterInterface $money)
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
        $counted = !in_array((string) $order['status'], ['awaiting_payment', 'cancelled'], true);

        return [
            'order_number' => (string) $order['order_number'],
            'total' => $this->money->format((int) $order['total_minor'], $currency, $locale),
            'email' => (string) ($order['customer_email'] ?? ''),
            'delivery' => $delivery,
            'payment' => $paymentCode !== '' ? ['code' => $paymentCode, 'label' => $this->methods->payment($paymentCode, $locale), 'pay_on_receipt' => $paymentCode === 'cash_on_delivery'] : null,
            'google' => $counted ? $this->googleData($orderId, $storeId, $order, $destination) : null,
            'items' => array_map(fn (array $i): array => ['name' => (string) $i['name'], 'quantity' => rtrim(rtrim((string) $i['quantity'], '0'), '.'), 'unit' => (string) $i['unit_code'], 'total' => $this->money->format((int) $i['line_total_minor'], $currency, $locale)], $items),
        ];
    }

    /**
     * What the Google Ads conversion and the Google Customer Reviews opt-in need. Only given for an order that is
     * neither waiting for payment nor cancelled, so an abandoned online payment is not counted as a sale.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $destination
     * @return array{id:string,value:string,currency:string,email:string,country:string,gtins:list<string>}
     */
    private function googleData(int $orderId, int $storeId, array $order, array $destination): array
    {
        $country = strtoupper((string) ($destination['country'] ?? ''));
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            $country = strtoupper((string) $this->db->fetchOne('SELECT default_country FROM mc_store WHERE id=?', [$storeId]));
        }
        $gtins = $this->db->fetchFirstColumn("SELECT DISTINCT v.gtin FROM mc_sales_order_item i JOIN mc_product_variant v ON v.id=i.variant_id WHERE i.order_id=? AND v.gtin<>'' LIMIT 50", [$orderId]);

        return [
            'id' => (string) $order['order_number'],
            'value' => number_format((int) $order['total_minor'] / 100, 2, '.', ''),
            'currency' => (string) $order['currency'],
            'email' => (string) ($order['customer_email'] ?? ''),
            'country' => $country,
            'gtins' => array_map('strval', $gtins),
        ];
    }
}
